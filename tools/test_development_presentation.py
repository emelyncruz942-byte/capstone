"""Tests use an in-memory artificial cohort; no database or private snapshots."""
import collections
import copy
import datetime as dt
import json
import tempfile
from pathlib import Path
import unittest

from generate_development_presentation import (
    CLASSES, TEACHER, SNAPSHOT_TABLES, TZ, WINDOW_START, FixtureBuilder,
    at_date, blank_mastery, dates, day, parse, replay_answer, stamp, uid,
    validate, write_package,
)


def synthetic_snapshot():
    data = {name: [] for name in SNAPSHOT_TABLES}
    for grade, count in ((4, 22), (5, 19), (6, 14)):
        data["classes"].append({"id": CLASSES[grade], "teacher_id": TEACHER, "grade_level": grade, "created_at": stamp(at_date(dt.date(2026, 10, 11)))})
        quiz_id, session_id = uid("test-source", grade), uid("test-session", grade)
        source = {"id": quiz_id, "grade_level": grade, "deleted_at": None, "topic": f"Grade {grade} arithmetic"}
        data["quizzes"].append(source)
        data["quiz_sessions"].append({"id": session_id, "grade_level": grade, "teacher_id": TEACHER, "class_id": CLASSES[grade], "source_quiz_id": quiz_id, "topic": source["topic"], "status": "completed", "created_at": stamp(at_date(dt.date(2026, 10, 11))), "assigned_at": stamp(at_date(dt.date(2026, 10, 11))), "available_at": stamp(at_date(dt.date(2026, 10, 12), 9)), "started_at": stamp(at_date(dt.date(2026, 10, 12), 9)), "ended_at": stamp(at_date(dt.date(2026, 10, 25), 20)), "due_at": None, "room_code": str(grade), "is_active": False, "retake_mode": False})
        for n in range(1, 6):
            q = {"id": grade * 10 + n, "quiz_id": quiz_id, "position": n, "grade": grade, "type": "multiple_choice", "question": f"{n} + {n} = ?", "choice1": str(n * 2), "choice2": str(n), "choice3": "0", "choice4": str(n * 3), "choice5": "", "choice6": "", "correct_answer": "0", "created_at": stamp(at_date(dt.date(2026, 10, 11)))}
            data["quiz_questions"].append(q)
        for n in range(count):
            sid = uid("test-profile", grade, n)
            data["profiles"].append({"id": sid, "role": "student", "grade_level": grade, "created_at": stamp(at_date(dt.date(2026, 10, 1))), "xp": 14, "points": 14, "trophies": 0, "level": 1})
            data["class_members"].append({"student_id": sid, "class_id": CLASSES[grade], "joined_at": stamp(at_date(dt.date(2026, 10, 11)))})
        first = data["profiles"][-count]["id"]
        data["quiz_results"].append({"id": uid("test-result", grade), "session_id": session_id, "student_id": first, "correct_answers": 4, "total_questions": 5, "rank": 1, "attempt_number": 1, "is_counted": True, "created_at": stamp(at_date(dt.date(2026, 10, 12), 10))})
        psid = uid("old-practice", grade)
        data["practice_sessions"].append({"id": psid, "student_id": first, "grade_level": grade, "started_at": stamp(at_date(dt.date(2026, 10, 12))), "status": "completed"})
        data["practice_questions"].append({"id": uid("old-question", grade), "session_id": psid, "student_id": first, "competency_key": f"g{grade}-addition", "difficulty": 1, "sequence": 1, "prompt": "7 + 8 = ?", "answer_type": "number", "options": [], "correct_answer": "15", "hint_steps": ["Add seven and eight."], "explanation": "7 + 8 = 15.", "hints_revealed": 0, "submitted_answer": "15", "is_correct": True, "xp_awarded": 14, "mastery_after": 8, "difficulty_after": 1, "combo_after": 1, "trophy_awarded": False, "response_ms": 10000, "created_at": stamp(at_date(dt.date(2026, 10, 12))), "answered_at": stamp(at_date(dt.date(2026, 10, 12)) + dt.timedelta(seconds=10))})
        for n in range(60):
            data["npc_quest_catalog"].append({"quest_name": f"M{n // 15 + 1}_G{grade}_NPC{n}", "grade_level": grade, "required_score": 5, "xp_reward": 50, "trophy_reward": 1})
    return data


class PracticeReplayTests(unittest.TestCase):
    def question(self, correct=True, hints=0, difficulty=1):
        return {"is_correct": correct, "hints_revealed": hints, "difficulty": difficulty, "answered_at": stamp(WINDOW_START)}

    def test_correct_threshold_level_up_and_trophy(self):
        state = blank_mastery("student", 4, "addition", WINDOW_START)
        state.update(correct_streak=2, difficulty=2)
        award, combo, correct = replay_answer(state, self.question(difficulty=2), 4, 9)
        self.assertEqual((state["mastery_score"], state["difficulty"], state["correct_streak"]), (9, 3, 0))
        self.assertEqual((award["xp_awarded"], combo, correct, award["trophy_awarded"]), (24, 5, 10, True))
        self.assertEqual(parse(state["next_review_at"]) - WINDOW_START, dt.timedelta(hours=12))

    def test_wrong_threshold_resets_streak_and_decreases_difficulty(self):
        state = blank_mastery("student", 4, "addition", WINDOW_START)
        state.update(mastery_score=2, incorrect_streak=1, difficulty=3, correct_streak=1)
        award, combo, correct = replay_answer(state, self.question(correct=False), 3, 9)
        self.assertEqual((state["mastery_score"], state["difficulty"], state["correct_streak"], state["incorrect_streak"]), (0, 2, 0, 0))
        self.assertEqual((award["xp_awarded"], combo, correct, award["trophy_awarded"]), (2, 0, 9, False))

    def test_hints_caps_and_review_intervals(self):
        state = blank_mastery("student", 6, "addition", WINDOW_START)
        state.update(mastery_score=98)
        award, _, _ = replay_answer(state, self.question(hints=5, difficulty=5), 0, 0)
        self.assertEqual((state["mastery_score"], state["hints_used"], award["xp_awarded"]), (100, 5, 16))
        self.assertEqual(parse(state["next_review_at"]) - WINDOW_START, dt.timedelta(days=7))


class PackageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.snapshot = synthetic_snapshot()
        cls.builder = FixtureBuilder(cls.snapshot).build()
        cls.validation = validate(cls.builder)

    def test_calendar_and_expected_schedule(self):
        self.assertEqual(len(list(dates())), 36)
        self.assertEqual(str(list(dates())[-1]), "2026-11-30")
        sessions = self.builder.payload["quiz_sessions"]
        for grade in CLASSES:
            target = [s for s in sessions if s["grade_level"] == grade and day(s["created_at"]) >= dt.date(2026, 10, 26)]
            weekly = collections.Counter(day(s["created_at"]).isocalendar().week for s in target if day(s["created_at"]) <= dt.date(2026, 11, 20))
            self.assertEqual(list(weekly.values()), [3, 2, 3, 2])
            self.assertEqual({day(s["created_at"]) for s in target if day(s["created_at"]) >= dt.date(2026, 11, 23)}, {dt.date(2026, 11, d) for d in range(23, 28)})

    def test_all_scopes_have_daily_coverage_and_varying_scores(self):
        self.assertTrue(self.validation["passed"])
        for row in self.validation["session_histograms"]:
            self.assertGreaterEqual(sum(v > 0 for v in row["histogram"].values()), 3)
            self.assertLessEqual(row["modal_share"], .65 if row["phase"] == "posttest" else .55)

    def test_generation_is_deterministic_and_does_not_mutate_input(self):
        original = copy.deepcopy(self.snapshot)
        second = FixtureBuilder(self.snapshot).build()
        self.assertEqual(self.snapshot, original)
        self.assertEqual(self.builder.payload, second.payload)
        self.assertEqual(self.builder.delete, second.delete)

    def test_source_content_is_preserved_and_bigint_ids_are_omitted(self):
        source = {q["question"]: q for q in self.snapshot["quiz_questions"]}
        for q in self.builder.payload["questions"]:
            self.assertNotIn("id", q)
            self.assertEqual(q["correct_answer"], source[q["question"]]["correct_answer"])
            self.assertEqual(q["choice1"], source[q["question"]]["choice1"])

    def test_validation_rejects_bad_score_and_wrong_practice_reward(self):
        bad = copy.deepcopy(self.builder)
        bad.payload["quiz_results"][0]["correct_answers"] = 6
        with self.assertRaisesRegex(ValueError, "Invalid quiz score"):
            validate(bad)
        bad = copy.deepcopy(self.builder)
        bad.payload["practice_questions"][0]["xp_awarded"] += 1
        with self.assertRaisesRegex(ValueError, "Practice replay mismatch"):
            validate(bad)

    def test_validation_rejects_result_before_profile_creation(self):
        bad = copy.deepcopy(self.builder)
        bad.payload["quiz_results"][0]["created_at"] = "2026-09-01T00:00:00+00:00"
        with self.assertRaisesRegex(ValueError, "Predates eligibility"):
            validate(bad)

    def test_manifest_retains_provenance_and_contains_no_auth_records(self):
        with tempfile.TemporaryDirectory() as folder:
            manifest, _ = write_package(self.builder, Path(folder), Path(folder) / "snapshot")
            self.assertIn("Synthetic", manifest["purpose"])
            self.assertEqual(manifest["range"]["activity_end_inclusive"], "2026-11-30")
            self.assertNotIn("audit_logs", self.builder.payload)
            self.assertNotIn("user_stats", self.builder.payload)
            for sid, contribution in self.builder.profile_contributions.items():
                profile = next(p for p in self.builder.payload["profiles"] if p["id"] == sid)
                self.assertEqual(profile["xp"], contribution["preserved_unattributed_baseline"]["xp"] + contribution["new_recorded_xp"])
                self.assertEqual(profile["level"], profile["xp"] // 250 + 1)
            self.assertTrue((Path(folder) / "README.md").exists())


if __name__ == "__main__":
    unittest.main()
