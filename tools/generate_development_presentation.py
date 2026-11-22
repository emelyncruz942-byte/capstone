#!/usr/bin/env python3
"""Build repeatable, local-only development fixtures from an allowlisted snapshot.

This program never connects to a database. Its manifest and README retain fixture
provenance outside the application screens. Applying or rolling back the package
is a separate, reviewed server-maintenance operation.
"""
from __future__ import annotations

import argparse
import collections
import copy
import datetime as dt
import hashlib
import json
import math
from pathlib import Path
import random
import uuid

TZ = dt.timezone(dt.timedelta(hours=8))
UTC = dt.timezone.utc
WINDOW_START = dt.datetime(2026, 10, 26, tzinfo=TZ)
WINDOW_END = dt.datetime(2026, 12, 1, tzinfo=TZ)
TEACHER = "375d6a66-8f25-4475-85d7-23b4e65433b4"
CLASSES = {4: "ab3ff840-3b0c-4f67-86d8-36bfeb8c8ee6", 5: "80ceaf9a-7210-4ea9-ac5d-4c5071b1a5c8", 6: "92e46c95-68da-4f30-b188-13efac4d2f09"}
NAMESPACE = uuid.uuid5(uuid.NAMESPACE_URL, "mathmetaverse/development-presentation/2026-11-22/v1")
GAMES = ("mental-arithmetic", "equation-balance", "pattern-pulse")
SNAPSHOT_TABLES = ("profiles", "classes", "class_members", "quizzes", "quiz_questions", "questions", "quiz_sessions", "quiz_session_students", "quiz_participants", "quiz_results", "practice_sessions", "practice_questions", "practice_mastery", "arcade_sessions", "arcade_scores", "arcade_achievements", "number_guess_sessions", "number_guess_scores", "user_quests", "npc_quest_catalog", "npc_quest_reward_receipts", "user_stats")
PRIMARY_KEYS = {"class_members": ("class_id", "student_id"), "quiz_participants": ("session_id", "student_id"), "quiz_session_students": ("session_id", "student_id"), "arcade_scores": ("student_id", "game_key"), "number_guess_scores": ("student_id",), "npc_quest_reward_receipts": ("user_id", "quest_name"), "profiles": ("id",)}


def parse(value):
    if isinstance(value, dt.datetime):
        return value
    return dt.datetime.fromisoformat(value.replace("Z", "+00:00"))


def stamp(value):
    return value.astimezone(UTC).isoformat(timespec="microseconds")


def day(value):
    return parse(value).astimezone(TZ).date()


def uid(*parts):
    return str(uuid.uuid5(NAMESPACE, "/".join(map(str, parts))))


def rng(*parts):
    return random.Random(int(hashlib.sha256("/".join(map(str, parts)).encode()).hexdigest(), 16))


def within(value):
    return value is not None and WINDOW_START <= parse(value) < WINDOW_END


def key(row, table):
    return tuple(row[k] for k in PRIMARY_KEYS.get(table, ("id",)))


def dates(start=WINDOW_START.date(), end=(WINDOW_END - dt.timedelta(days=1)).date()):
    while start <= end:
        yield start
        start += dt.timedelta(days=1)


def at_date(date, hour=15, minute=0, second=0):
    return dt.datetime.combine(date, dt.time(hour, minute, second), TZ)


def read_snapshot(folder):
    result = {}
    for table in SNAPSHOT_TABLES:
        path = folder / f"{table}.json"
        if path.exists():
            result[table] = json.loads(path.read_text(encoding="utf-8-sig"))
        else:
            chunks = sorted((folder / "chunks").glob(f"{table}.*.json"))
            if not chunks:
                raise FileNotFoundError(f"Missing required snapshot table: {table}")
            result[table] = [r for part in chunks for r in json.loads(part.read_text(encoding="utf-8-sig"))]
    return result


def phase(date):
    if date < dt.date(2026, 10, 26):
        return "pretest"
    if date < dt.date(2026, 11, 2):
        return "week_1"
    if date < dt.date(2026, 11, 9):
        return "week_2"
    if date < dt.date(2026, 11, 16):
        return "week_3"
    if date < dt.date(2026, 11, 23):
        return "week_4"
    return "posttest"


def student_traits(profiles):
    result = {}
    for grade in CLASSES:
        students = sorted((p for p in profiles if p["grade_level"] == grade), key=lambda p: rng("ability", p["id"]).random())
        for n, p in enumerate(students):
            result[p["id"]] = {"ability": (n + .5) / len(students), "struggling": n < max(2, round(len(students) * .12)), "learning": rng("learning", p["id"]).uniform(.8, 1.2)}
    return result


def score_probability(traits, date):
    base = {"pretest": .32, "week_1": .41, "week_2": .49, "week_3": .57, "week_4": .65, "posttest": .90}[phase(date)]
    if traits["struggling"]:
        return min(.46, base * .55 + .035)
    return min(.97, max(.08, base + (traits["ability"] - .5) * .26 + (traits["learning"] - 1) * .12))


def quiz_scores(students, session_id, date, traits):
    scores = {}
    for sid in students:
        r = rng("quiz", session_id, sid)
        p = min(.98, max(.04, score_probability(traits[sid], date) + r.uniform(-.12, .12)))
        scores[sid] = sum(r.random() < p for _ in range(5))
    # A small class can legitimately cluster, but never make an entire class a
    # constant. Keep both failure and success cases in posttests for UI coverage.
    limit = .65 if phase(date) == "posttest" else .55
    r = rng("dispersion", session_id)
    for _ in range(len(scores) * 3):
        mode, count = collections.Counter(scores.values()).most_common(1)[0]
        if count / len(scores) <= limit:
            break
        candidates = [s for s in students if scores[s] == mode]
        target = r.choice(candidates)
        scores[target] = max(0, min(5, mode + r.choice([-1, 1])))
    if phase(date) == "posttest":
        target_passes = round(len(students) * rng("pass-share", session_id).uniform(.79, .88))
        ordered = sorted(students, key=lambda s: (traits[s]["struggling"], -traits[s]["ability"], rng(session_id, s, "post").random()))
        passes = set(ordered[:target_passes])
        for sid in students:
            if sid in passes and scores[sid] < 4:
                scores[sid] = 4 + (rng("pass-score", session_id, sid).random() < .45)
            if sid not in passes and scores[sid] >= 4:
                scores[sid] = rng("low-score", session_id, sid).choices([0, 1, 2, 3], [1, 2, 4, 5])[0]
        # Preserve the passing share while limiting either 4 or 5 as the mode.
        while True:
            mode, count = collections.Counter(scores.values()).most_common(1)[0]
            if count / len(scores) <= limit:
                break
            target = r.choice([s for s in students if scores[s] == mode])
            scores[target] = 9 - mode if mode >= 4 else (mode + 1) % 4
    return scores


def replay_answer(state, question, combo, correct_total):
    """The production submit_practice_answer formulas, with historical time."""
    correct = question["is_correct"]
    hints = question["hints_revealed"]
    if correct:
        state["correct_streak"] += 1
        state["incorrect_streak"] = 0
        state["mastery_score"] = min(100, state["mastery_score"] + max(3, 8 - min(hints * 2, 6)) + max(0, question["difficulty"] - 1))
        if state["correct_streak"] >= 3:
            state["difficulty"] = min(5, state["difficulty"] + 1)
            state["correct_streak"] = 0
    else:
        state["correct_streak"] = 0
        state["incorrect_streak"] += 1
        state["mastery_score"] = max(0, state["mastery_score"] - 3)
        if state["incorrect_streak"] >= 2:
            state["difficulty"] = max(1, state["difficulty"] - 1)
            state["incorrect_streak"] = 0
    state["attempts"] += 1
    state["correct_answers"] += int(correct)
    state["hints_used"] += hints
    combo = combo + 1 if correct else 0
    correct_total += int(correct)
    xp = max(8, 10 + question["difficulty"] * 2 + min(combo, 5) * 2 - min(hints * 2, 6)) if correct else 2
    trophy = correct and correct_total % 10 == 0
    when = parse(question["answered_at"])
    interval = dt.timedelta(days=7 if state["mastery_score"] >= 90 else 3 if state["mastery_score"] >= 70 else 1) if state["mastery_score"] >= 40 else dt.timedelta(hours=12)
    state.update(last_practiced_at=stamp(when), next_review_at=stamp(when + interval), updated_at=stamp(when))
    return {"xp_awarded": xp, "mastery_after": state["mastery_score"], "difficulty_after": state["difficulty"], "combo_after": combo, "trophy_awarded": trophy}, combo, correct_total


def blank_mastery(sid, grade, competency, when, original=None):
    return {"id": (original or {}).get("id", uid("mastery", sid, competency)), "student_id": sid, "grade_level": grade, "competency_key": competency, "mastery_score": 0, "difficulty": 1, "attempts": 0, "correct_answers": 0, "hints_used": 0, "correct_streak": 0, "incorrect_streak": 0, "last_practiced_at": None, "next_review_at": None, "created_at": (original or {}).get("created_at", stamp(when)), "updated_at": stamp(when)}


class FixtureBuilder:
    def __init__(self, snapshot):
        self.old = copy.deepcopy(snapshot)
        self.profiles = {p["id"]: p for p in snapshot["profiles"]}
        assert len(self.profiles) == 55 and collections.Counter(p["grade_level"] for p in self.profiles.values()) == {4: 22, 5: 19, 6: 14}, "Unexpected cohort: review scope before generating"
        self.traits = student_traits(list(self.profiles.values()))
        self.payload = {}
        self.delete = {}
        self.base_mastery = {}
        self.profile_contributions = {}
        self.audit_notes = []
        self.members = self.build_members()

    def build_members(self):
        members = {m["student_id"]: copy.deepcopy(m) for m in self.old["class_members"] if m["class_id"] in CLASSES.values()}
        classes = {c["id"]: c for c in self.old["classes"]}
        for sid, profile in self.profiles.items():
            class_id = CLASSES[profile["grade_level"]]
            floor = max(parse(profile["created_at"]), parse(classes[class_id]["created_at"]))
            if sid in members:
                assert members[sid]["class_id"] == class_id
                assert parse(members[sid]["joined_at"]) >= floor
                continue
            historical = [parse(e["eligible_at"]) for e in self.old["quiz_session_students"] if e["student_id"] == sid]
            joined = max(floor, min(historical) if historical else floor + dt.timedelta(hours=1))
            members[sid] = {"student_id": sid, "class_id": class_id, "joined_at": stamp(joined)}
        self.payload["class_members"] = [m for m in members.values() if not any(key(m, "class_members") == key(o, "class_members") for o in self.old["class_members"])]
        return members

    def eligible(self, date, grade=None):
        cutoff = at_date(date, 20)
        return sorted(sid for sid, p in self.profiles.items() if (grade is None or p["grade_level"] == grade) and max(parse(p["created_at"]), parse(self.members[sid]["joined_at"])) <= cutoff)

    def build_quizzes(self):
        sessions = {s["id"]: copy.deepcopy(s) for s in self.old["quiz_sessions"]}
        results = copy.deepcopy(self.old["quiz_results"])
        eligibility = {(r["session_id"], r["student_id"]): copy.deepcopy(r) for r in self.old["quiz_session_students"]}
        participants = {(r["session_id"], r["student_id"]): copy.deepcopy(r) for r in self.old["quiz_participants"]}
        questions = []
        sources = {g: sorted([q for q in self.old["quizzes"] if q["grade_level"] == g and not q["deleted_at"]], key=lambda q: q["id"]) for g in CLASSES}
        source_questions = collections.defaultdict(list)
        for q in self.old["quiz_questions"]:
            source_questions[q["quiz_id"]].append(q)
        schedule = [dt.date(2026, 10, d) for d in (26, 28, 30)] + [dt.date(2026, 11, d) for d in (2, 5, 9, 11, 13, 16, 19, 23, 24, 25, 26, 27)]
        planned = []
        for grade in CLASSES:
            for n, date in enumerate(schedule):
                existing = next((s for s in sessions.values() if s["grade_level"] == grade and day(s["created_at"]) == date and not any(r["session_id"] == s["id"] for r in results)), None)
                if existing:
                    s = existing
                else:
                    source = sources[grade][n % len(sources[grade])]
                    start = at_date(date, 9 + grade - 4)
                    s = {"id": uid("quiz-session", grade, date), "teacher_id": TEACHER, "room_code": str(rng("room-code", grade, date).randrange(100000, 1000000)), "topic": source["topic"], "is_active": False, "created_at": stamp(start - dt.timedelta(hours=1)), "max_members": 60, "status": "completed", "class_id": CLASSES[grade], "time_limit": 20, "source_quiz_id": source["id"], "assigned_at": stamp(start - dt.timedelta(hours=1)), "available_at": stamp(start), "due_at": stamp(at_date(date, 20)), "started_at": stamp(start), "ended_at": stamp(at_date(date, 20)), "retake_mode": False, "grade_level": grade}
                    template = sorted(source_questions[source["id"]], key=lambda q: q["position"])
                    assert len(template) == 5
                    for q in template:
                        row = {k: v for k, v in q.items() if k not in ("id", "quiz_id", "position")}
                        row.update(session_id=s["id"], created_at=stamp(start - dt.timedelta(hours=1)), deleted_at=None)
                        questions.append(row)  # bigint identity supplied by PostgreSQL on import
                    sessions[s["id"]] = s
                planned.append((s["id"], date))
        # Re-score every retained attempt, preserving row IDs, original completion
        # times and the latest attempt as the single counted result.
        for session_id, s in sessions.items():
            old_rows = [r for r in results if r["session_id"] == session_id]
            planned_date = next((date for sid, date in planned if sid == session_id), None)
            if planned_date:
                completion_date = planned_date
            else:
                assert old_rows, f"Unplanned empty assignment {session_id}"
                completion_date = min(dt.date(2026, 10, 25), max(day(r["created_at"]) for r in old_rows))
            eligible_students = self.eligible(completion_date, s["grade_level"])
            # Existing valid attempt owners remain represented even when they left
            # the class. Re-enrollment above restores their original eligibility.
            eligible_students = sorted(set(eligible_students) | {r["student_id"] for r in old_rows})
            scores = quiz_scores(eligible_students, session_id, completion_date, self.traits)
            for sid in eligible_students:
                rows = sorted([r for r in old_rows if r["student_id"] == sid], key=lambda r: (r["attempt_number"], r["created_at"]))
                if not rows:
                    earliest = max(parse(self.members[sid]["joined_at"]), parse(self.profiles[sid]["created_at"]), parse(s.get("available_at") or s["assigned_at"]))
                    r = rng("quiz-time", session_id, sid)
                    when = max(earliest + dt.timedelta(minutes=15), at_date(completion_date, 10 + s["grade_level"] - 4, r.randrange(60), r.randrange(60)))
                    rows = [{"id": uid("quiz-result", session_id, sid), "session_id": session_id, "student_id": sid, "correct_answers": 0, "total_questions": 5, "rank": 0, "created_at": stamp(when), "attempt_number": 1, "is_counted": True}]
                    results.extend(rows)
                for n, row in enumerate(rows):
                    score = scores[sid]
                    if n < len(rows) - 1:
                        score = max(0, score - rng("retake", row["id"]).choice([0, 1, 2]))
                    row.update(correct_answers=score, total_questions=5, attempt_number=n + 1, is_counted=n == len(rows) - 1)
                    valid_after = max(parse(self.members[sid]["joined_at"]), parse(self.profiles[sid]["created_at"]), parse(s["assigned_at"])) + dt.timedelta(minutes=15+n*2)
                    if parse(row["created_at"]) < valid_after:
                        row["created_at"] = stamp(valid_after)
                member_since = max(parse(self.members[sid]["joined_at"]), parse(self.profiles[sid]["created_at"]), parse(s["assigned_at"]))
                e = eligibility.get((session_id, sid), {"session_id": session_id, "student_id": sid, "eligibility_status": "eligible", "allowed_attempts": len(rows), "eligible_at": stamp(member_since), "excused_at": None, "excused_by": None, "excuse_reason": None, "last_retake_granted_at": None, "last_retake_granted_by": None, "retake_due_at": None, "retake_reason": None})
                e.update(eligibility_status="eligible", allowed_attempts=max(e["allowed_attempts"], len(rows)), eligible_at=stamp(member_since))
                eligibility[session_id, sid] = e
                first = min(parse(r["created_at"]) for r in rows)
                participants[session_id, sid] = {"session_id": session_id, "student_id": sid, "joined_at": stamp(max(member_since, first - dt.timedelta(seconds=120)))}
            if planned_date:
                start = at_date(completion_date, 9 + s["grade_level"] - 4)
                s.update(status="completed", is_active=False, available_at=stamp(start), started_at=stamp(start), due_at=stamp(at_date(completion_date, 20)), ended_at=stamp(at_date(completion_date, 20)))
            final_times = [parse(r["created_at"]) for r in results if r["session_id"] == session_id]
            if s.get("ended_at") and parse(s["ended_at"]) < max(final_times):
                s["ended_at"] = stamp(max(final_times) + dt.timedelta(minutes=1))
            if s.get("due_at") and parse(s["due_at"]) <= max(final_times):
                s["due_at"] = stamp(max(final_times) + dt.timedelta(minutes=1))
            counted = sorted((r for r in results if r["session_id"] == session_id and r["is_counted"]), key=lambda r: (-r["correct_answers"], parse(r["created_at"]), r["student_id"]))
            for n, row in enumerate(counted, 1):
                row["rank"] = n
            for row in (r for r in results if r["session_id"] == session_id and not r["is_counted"]):
                row["rank"] = 0
        # Drop eligibility/participant rows for late members that have no attempt.
        owners = {(r["session_id"], r["student_id"]) for r in results}
        self.payload.update(quiz_sessions=list(sessions.values()), questions=questions, quiz_results=results, quiz_session_students=[r for k, r in eligibility.items() if k in owners], quiz_participants=[r for k, r in participants.items() if k in owners])
        for table in ("quiz_results", "quiz_session_students", "quiz_participants"):
            self.delete[table] = [dict(zip(PRIMARY_KEYS.get(table, ("id",)), key(r, table))) for r in self.old[table]]

    def build_practice(self):
        old_sessions = {s["id"]: s for s in self.old["practice_sessions"]}
        replaced_sessions = {s["id"] for s in old_sessions.values() if within(s["started_at"])}
        replaced_questions = {q["id"] for q in self.old["practice_questions"] if q["session_id"] in replaced_sessions or within(q.get("answered_at") or q["created_at"])}
        # Avoid retaining a partial session whose questions straddle the boundary.
        replaced_sessions |= {q["session_id"] for q in self.old["practice_questions"] if q["id"] in replaced_questions}
        replaced_questions |= {q["id"] for q in self.old["practice_questions"] if q["session_id"] in replaced_sessions}
        self.delete["practice_sessions"] = [{"id": s} for s in sorted(replaced_sessions)]
        self.delete["practice_questions"] = [{"id": q} for q in sorted(replaced_questions)]
        retained = sorted([q for q in self.old["practice_questions"] if q["id"] not in replaced_questions and q["answered_at"]], key=lambda q: parse(q["answered_at"]))
        originals = {(m["student_id"], m["competency_key"]): m for m in self.old["practice_mastery"]}
        mastery = {}
        for k, original in originals.items():
            mastery[k] = blank_mastery(k[0], original["grade_level"], k[1], WINDOW_START, original)
        for q in retained:
            k = q["student_id"], q["competency_key"]
            m = mastery.setdefault(k, blank_mastery(k[0], self.profiles[k[0]]["grade_level"], k[1], parse(q["created_at"])))
            replay_answer(m, q, 0, 0)
            # Legacy fixtures used an older mastery model. Preserve the persisted
            # endpoint and replay current rules only after this boundary.
            if q["mastery_after"] is not None:
                m["mastery_score"] = q["mastery_after"]
            if q["difficulty_after"] is not None:
                m["difficulty"] = q["difficulty_after"]
        self.base_mastery = copy.deepcopy(mastery)
        bank = collections.defaultdict(list)
        for q in self.old["practice_questions"]:
            grade = self.profiles[q["student_id"]]["grade_level"]
            bank[grade].append(q)
        sessions, questions = [], []
        for date in dates():
            pool = self.eligible(date)
            attendees = [sid for sid in pool if rng("practice-attend", date, sid).random() < (.18 if date.weekday() > 4 else .28)]
            if not attendees:
                attendees = [rng("practice-min", date).choice(pool)]
            for sid in attendees:
                r = rng("practice-run", date, sid)
                grade = self.profiles[sid]["grade_level"]
                when = max(at_date(date, r.randrange(14, 20), r.randrange(60), r.randrange(60)), parse(self.members[sid]["joined_at"]) + dt.timedelta(minutes=20))
                session_id = uid("practice-session", date, sid)
                mode = r.choices(["adventure", "daily", "review", "focus"], [4, 3, 2, 1])[0]
                chosen = r.choice(bank[grade])["competency_key"]
                count = r.randrange(5, 13)
                combo = correct_total = xp = maximum = 0
                start = when
                for n in range(1, count + 1):
                    template = r.choice([q for q in bank[grade] if mode != "focus" or q["competency_key"] == chosen])
                    competency = template["competency_key"]
                    k = sid, competency
                    m = mastery.setdefault(k, blank_mastery(sid, grade, competency, when, originals.get(k)))
                    question = {field: copy.deepcopy(template[field]) for field in ("prompt", "answer_type", "options", "correct_answer", "hint_steps", "explanation")}
                    probability = min(.94, score_probability(self.traits[sid], date) + .13)
                    correct = r.random() < probability
                    hints = min(len(question["hint_steps"]), r.choices([0, 1, 2], [7, 2, 1])[0])
                    response = r.randrange(9000, 60000) + hints * r.randrange(4000, 15000)
                    answered = when + dt.timedelta(milliseconds=response)
                    if correct:
                        submitted = question["correct_answer"]
                    else:
                        wrong = [v for v in question["options"] if str(v).strip().lower() != question["correct_answer"].strip().lower()]
                        submitted = str(r.choice(wrong)) if wrong else str(float(question["correct_answer"]) + r.choice([1, 2, -1])) if question["answer_type"] == "number" else "Not sure"
                    question.update(id=uid("practice-question", date, sid, n), session_id=session_id, student_id=sid, competency_key=competency, difficulty=m["difficulty"], sequence=n, hints_revealed=hints, submitted_answer=submitted, is_correct=correct, response_ms=response, created_at=stamp(when), answered_at=stamp(answered))
                    award, combo, correct_total = replay_answer(m, question, combo, correct_total)
                    question.update(award)
                    questions.append(question)
                    xp += award["xp_awarded"]
                    maximum = max(maximum, combo)
                    when = answered + dt.timedelta(seconds=r.randrange(3, 12))
                sessions.append({"id": session_id, "student_id": sid, "grade_level": grade, "mode": mode, "status": "completed", "questions_answered": count, "correct_answers": correct_total, "xp_earned": xp, "current_combo": combo, "max_combo": maximum, "started_at": stamp(start), "last_activity_at": questions[-1]["answered_at"], "completed_at": stamp(when), "focus_competency_key": chosen if mode == "focus" else None})
        self.payload.update(practice_sessions=sessions, practice_questions=questions, practice_mastery=list(mastery.values()))
        self.all_practice_questions = retained + questions

    def build_games(self):
        generated = {"arcade_sessions": [], "number_guess_sessions": []}
        for date in dates():
            pool = self.eligible(date)
            for table in generated:
                attendees = [sid for sid in pool if rng(table, "attend", date, sid).random() < (.23 if date.weekday() > 4 else .36)]
                if not attendees:
                    attendees = [rng(table, "min", date).choice(pool)]
                for sid in attendees:
                    r = rng(table, date, sid)
                    when = max(at_date(date, r.randrange(14, 20), r.randrange(60), r.randrange(60)), parse(self.members[sid]["joined_at"]) + dt.timedelta(minutes=10))
                    grade = self.profiles[sid]["grade_level"]
                    p = min(.96, score_probability(self.traits[sid], date) + .16)
                    if table == "arcade_sessions":
                        game = r.choice(GAMES)
                        answers = [r.random() < p for _ in range(r.randrange(7, 18))]
                        combo = maximum = 0
                        for answer in answers:
                            combo = combo + 1 if answer else 0
                            maximum = max(maximum, combo)
                        a, b = r.randrange(2, 20), r.randrange(2, 20)
                        challenge = {"prompt": f"{a} + {b} = ?", "answer_type": "number", "options": [], "answer": str(a + b), "explanation": f"Add {a} and {b} to obtain {a+b}."}
                        if game == "equation-balance":
                            challenge.update(prompt=f"x + {b} = {a+b}. Find x.", answer=str(a), explanation=f"Subtract {b} from both sides.")
                        elif game == "pattern-pulse":
                            challenge.update(prompt=f"{a}, {a+b}, {a+2*b}, {a+3*b}, ?", answer=str(a+4*b), explanation=f"Add {b} each time.")
                        finish = when + dt.timedelta(seconds=60)
                        row = {"id": uid(table, date, sid), "student_id": sid, "game_key": game, "grade_level": grade, "status": "expired", "score": sum(answers), "total_answers": len(answers), "correct_answers": sum(answers), "current_streak": combo, "max_streak": maximum, "sequence": len(answers) + 1, "challenge": challenge, "started_at": stamp(when), "expires_at": stamp(finish), "completed_at": stamp(finish), "updated_at": stamp(finish)}
                    else:
                        score = sum(r.random() < p for _ in range(r.randrange(3, 11)))
                        guesses = max(1, score + r.randrange(3, 18))
                        expires = when + dt.timedelta(seconds=60 + score * 15)
                        finish = when + dt.timedelta(seconds=r.randrange(35, 60) + score * 12)
                        row = {"id": uid(table, date, sid), "student_id": sid, "status": "finished", "score": score, "total_guesses": guesses, "range_max": 100 + score * 50, "target_number": r.randrange(1, 101 + score * 50), "started_at": stamp(when), "expires_at": stamp(expires), "last_guess_at": stamp(finish - dt.timedelta(seconds=1)), "completed_at": stamp(finish), "updated_at": stamp(finish)}
                    generated[table].append(row)
        for table, new in generated.items():
            removed = [r for r in self.old[table] if within(r["started_at"])]
            self.delete[table] = [{"id": r["id"]} for r in removed]
            self.payload[table] = new
        self.all_arcade = [r for r in self.old["arcade_sessions"] if not within(r["started_at"])] + generated["arcade_sessions"]
        self.all_numbers = [r for r in self.old["number_guess_sessions"] if not within(r["started_at"])] + generated["number_guess_sessions"]
        self.payload["arcade_scores"] = []
        self.payload["number_guess_scores"] = []
        self.payload["arcade_achievements"] = []
        existing_ach = {(r["student_id"], r["achievement_key"]): r for r in self.old["arcade_achievements"]}
        for sid in self.profiles:
            runs = sorted([dict(r, game_key="number-guess") for r in self.all_numbers if r["student_id"] == sid and r["status"] != "active"] + [r for r in self.all_arcade if r["student_id"] == sid and r["status"] != "active"], key=lambda r: parse(r["completed_at"]))
            scores, game_counts, unlocked, best_streaks = {}, collections.Counter(), {}, {}
            for n, run in enumerate(runs, 1):
                game = run["game_key"]
                game_counts[game] += 1
                if run["score"] > scores.get(game, -1):
                    scores[game] = run["score"]
                    best_streaks[game] = run.get("max_streak", 0)
                elif run["score"] == scores[game]:
                    best_streaks[game] = max(best_streaks.get(game, 0), run.get("max_streak", 0))
                max_streak = max(best_streaks.values())
                games_scored = sum(v > 0 for v in scores.values())
                criteria = {"first-launch": (n >= 1, {"runs": n}), "score-five": (max(scores.values()) >= 5, {"score": max(scores.values())}), "score-ten": (max(scores.values()) >= 10, {"score": max(scores.values())}), "streak-five": (max_streak >= 5, {"streak": max_streak}), "arcade-explorer": (games_scored >= 3, {"games": games_scored}), "arcade-master": (games_scored >= 5, {"games": games_scored}), "arcade-veteran": (n >= 25, {"runs": n})}
                for name, game_name, threshold in (("number-navigator", "number-guess", 5), ("mental-meteor", "mental-arithmetic", 10), ("equation-engineer", "equation-balance", 10), ("fraction-photon", "fraction-comparison", 10), ("pattern-pilot", "pattern-pulse", 10)):
                    criteria[name] = scores.get(game_name, 0) >= threshold, {"score": scores.get(game_name, 0)}
                for name, (qualified, metadata) in criteria.items():
                    if qualified and name not in unlocked:
                        old = existing_ach.get((sid, name))
                        unlocked[name] = {"id": (old or {}).get("id", uid("achievement", sid, name)), "student_id": sid, "achievement_key": name, "game_key": None, "metadata": metadata, "unlocked_at": run["completed_at"]}
            self.payload["arcade_achievements"].extend(unlocked.values())
            for game in sorted({r["game_key"] for r in runs}):
                selected = [r for r in runs if r["game_key"] == game]
                latest = max(r["completed_at"] for r in selected)
                if game == "number-guess":
                    best = min(selected, key=lambda r: (-r["score"], r["total_guesses"], parse(r["completed_at"])))
                    self.payload["number_guess_scores"].append({"student_id": sid, "best_score": best["score"], "best_guesses": best["total_guesses"] if best["score"] else None, "games_played": len(selected), "achieved_at": best["last_guess_at"] if best["score"] else None, "updated_at": latest})
                else:
                    best = min(selected, key=lambda r: (-r["score"], -r["max_streak"], parse(r["completed_at"])))
                    self.payload["arcade_scores"].append({"student_id": sid, "game_key": game, "best_score": best["score"], "best_streak": best["max_streak"], "games_played": len(selected), "achieved_at": best["completed_at"] if best["score"] else None, "updated_at": latest})
        for table in ("arcade_scores", "number_guess_scores", "arcade_achievements"):
            self.delete[table] = [dict(zip(PRIMARY_KEYS.get(table, ("id",)), key(r, table))) for r in self.old[table]]

    def build_npcs(self):
        quests = {(q["user_id"], q["quest_name"]): copy.deepcopy(q) for q in self.old["user_quests"]}
        # Legacy NULL-score, zero-reward receipts remain valid historical markers.
        receipts = copy.deepcopy(self.old["npc_quest_reward_receipts"])
        completed = {(r["user_id"], r["quest_name"]) for r in receipts}
        catalog = collections.defaultdict(list)
        for q in self.old["npc_quest_catalog"]:
            catalog[q["grade_level"]].append(q["quest_name"])
        for grade in catalog:
            catalog[grade].sort()
        for date in dates():
            pool = self.eligible(date)
            attendees = [sid for sid in pool if rng("npc-attend", date, sid).random() < .32]
            if not attendees:
                attendees = [rng("npc-min", date).choice(pool)]
            for sid in attendees:
                r = rng("npc", date, sid)
                available = [name for name in catalog[self.profiles[sid]["grade_level"]] if (sid, name) not in completed and quests.get((sid, name), {}).get("quest_status") != "success"]
                if not available:
                    continue
                name = r.choice(available)
                when = max(at_date(date, r.randrange(14, 20), r.randrange(60), r.randrange(60)), parse(self.members[sid]["joined_at"]) + dt.timedelta(minutes=30))
                q = quests.get((sid, name), {"id": uid("npc-quest", sid, name), "user_id": sid, "quest_name": name})
                q.update(quest_status="success", progress=5, updated_at=stamp(when))
                quests[sid, name] = q
                receipts.append({"user_id": sid, "quest_name": name, "score": 5, "xp_awarded": 50, "trophies_awarded": 1, "awarded_at": stamp(when)})
                completed.add((sid, name))
        self.payload["user_quests"] = list(quests.values())
        self.payload["npc_quest_reward_receipts"] = receipts
        self.delete["user_quests"] = [{"id": q["id"]} for q in self.old["user_quests"]]
        self.delete["npc_quest_reward_receipts"] = [dict(user_id=r["user_id"], quest_name=r["quest_name"]) for r in self.old["npc_quest_reward_receipts"]]

    def build_profiles(self):
        output = []
        for sid, original in self.profiles.items():
            old_q = [q for q in self.old["practice_questions"] if q["student_id"] == sid]
            new_q = [q for q in self.all_practice_questions if q["student_id"] == sid]
            old_npc = [q for q in self.old["npc_quest_reward_receipts"] if q["user_id"] == sid]
            new_npc = [q for q in self.payload["npc_quest_reward_receipts"] if q["user_id"] == sid]
            old_xp = sum(q["xp_awarded"] for q in old_q) + sum(q["xp_awarded"] for q in old_npc)
            new_xp = sum(q["xp_awarded"] for q in new_q) + sum(q["xp_awarded"] for q in new_npc)
            old_trophies = sum(bool(q["trophy_awarded"]) for q in old_q) + sum(q["trophies_awarded"] for q in old_npc)
            new_trophies = sum(bool(q["trophy_awarded"]) for q in new_q) + sum(q["trophies_awarded"] for q in new_npc)
            baseline = {"xp": max(0, (original["xp"] or 0) - old_xp), "points": max(0, (original["points"] or 0) - old_xp), "trophies": max(0, (original["trophies"] or 0) - old_trophies)}
            self.profile_contributions[sid] = {"original": {k: original.get(k) for k in ("xp", "points", "trophies", "level")}, "preserved_unattributed_baseline": baseline, "old_recorded_xp": old_xp, "new_recorded_xp": new_xp, "old_recorded_trophies": old_trophies, "new_recorded_trophies": new_trophies}
            xp = baseline["xp"] + new_xp
            output.append({"id": sid, "class_id": CLASSES[original["grade_level"]], "xp": xp, "points": baseline["points"] + new_xp, "trophies": baseline["trophies"] + new_trophies, "level": max(1, xp // 250 + 1)})
        self.payload["profiles"] = output

    def build(self):
        self.build_quizzes()
        self.build_practice()
        self.build_games()
        self.build_npcs()
        self.build_profiles()
        return self


def validate(builder):
    errors = []
    def require(condition, message):
        if not condition:
            errors.append(message)
    p = builder.payload
    for table, rows in p.items():
        if table != "questions":
            require(len(rows) == len({key(r, table) for r in rows}), f"Duplicate key: {table}")
    profiles = builder.profiles
    for table, timefield, userfield in (("quiz_results", "created_at", "student_id"), ("practice_sessions", "started_at", "student_id"), ("practice_questions", "created_at", "student_id"), ("arcade_sessions", "started_at", "student_id"), ("number_guess_sessions", "started_at", "student_id"), ("npc_quest_reward_receipts", "awarded_at", "user_id")):
        for row in p[table]:
            sid = row[userfield]
            floor = max(parse(profiles[sid]["created_at"]), parse(builder.members[sid]["joined_at"]))
            # Legacy NPC records are preserved even when earlier than a newly
            # restored membership. All generated records obey both boundaries.
            if table == "npc_quest_reward_receipts" and row in builder.old[table]:
                continue
            require(parse(row[timefield]) >= floor, f"Predates eligibility: {table}/{row.get('id', sid)}")
    sessions = {s["id"]: s for s in p["quiz_sessions"]}
    eligibility = {(e["session_id"], e["student_id"]): e for e in p["quiz_session_students"]}
    participants = {(e["session_id"], e["student_id"]): e for e in p["quiz_participants"]}
    for r in p["quiz_results"]:
        s = sessions[r["session_id"]]
        e = eligibility[r["session_id"], r["student_id"]]
        participant = participants[r["session_id"], r["student_id"]]
        when = parse(r["created_at"])
        require(when >= parse(s.get("available_at") or s["assigned_at"]) and when >= parse(e["eligible_at"]) and when >= parse(participant["joined_at"]), f"Quiz before schedule/eligibility/participation: {r['id']}")
        require(not s.get("due_at") or when < parse(s["due_at"]), f"Quiz after deadline: {r['id']}")
        require(not s.get("ended_at") or when <= parse(s["ended_at"]), f"Quiz after session end: {r['id']}")
        require(r["attempt_number"] <= e["allowed_attempts"], f"Quiz attempt allowance: {r['id']}")
    histograms = []
    for sid, session in sessions.items():
        rows = [r for r in p["quiz_results"] if r["session_id"] == sid and r["is_counted"]]
        require(bool(rows), f"Empty assignment: {sid}")
        require(all(r["total_questions"] == 5 and 0 <= r["correct_answers"] <= 5 for r in rows), f"Invalid quiz score: {sid}")
        require(len(rows) == len({r["student_id"] for r in rows}), f"Multiple counted attempts: {sid}")
        counts = collections.Counter(r["correct_answers"] for r in rows)
        modal_share = max(counts.values()) / len(rows)
        completion = max(day(r["created_at"]) for r in rows)
        limit = .65 if phase(completion) == "posttest" else .55
        require(len(counts) >= 3 and modal_share <= limit + 1e-9, f"Insufficient dispersion: {sid}/{dict(counts)}")
        if phase(completion) == "posttest":
            passing = sum(r["correct_answers"] >= 4 for r in rows) / len(rows)
            require(.75 <= passing <= .91, f"Posttest passing share: {sid}/{passing}")
        histograms.append({"session_id": sid, "grade": session["grade_level"], "topic": session["topic"], "phase": phase(completion), "date": str(completion), "students": len(rows), "histogram": {str(n): counts[n] for n in range(6)}, "mean": round(sum(r["correct_answers"] for r in rows) / len(rows), 4), "modal_share": round(modal_share, 4), "pass_rate": round(sum(r["correct_answers"] >= 4 for r in rows) / len(rows), 4)})
    for table, timestamp in (("practice_sessions", "started_at"), ("arcade_sessions", "started_at"), ("number_guess_sessions", "started_at"), ("npc_quest_reward_receipts", "awarded_at")):
        present = {day(r[timestamp]) for r in p[table] if within(r[timestamp])}
        require(set(dates()) <= present, f"Missing daily activity: {table}/{sorted(set(dates())-present)}")
    mastery = copy.deepcopy(builder.base_mastery)
    questions_by_session = collections.defaultdict(list)
    for q in p["practice_questions"]:
        questions_by_session[q["session_id"]].append(q)
    for s in sorted(p["practice_sessions"], key=lambda r: parse(r["started_at"])):
        combo = total = xp = maximum = 0
        rows = sorted(questions_by_session[s["id"]], key=lambda q: q["sequence"])
        for n, q in enumerate(rows, 1):
            k = q["student_id"], q["competency_key"]
            m = mastery.setdefault(k, blank_mastery(k[0], s["grade_level"], k[1], parse(q["created_at"])))
            require(q["sequence"] == n and q["difficulty"] == m["difficulty"], f"Practice sequence/difficulty: {q['id']}")
            award, combo, total = replay_answer(m, q, combo, total)
            require(all(q[field] == value for field, value in award.items()), f"Practice replay mismatch: {q['id']}")
            if q["answer_type"] == "number":
                try:
                    actual = abs(float(q["submitted_answer"].replace(",", "")) - float(q["correct_answer"].replace(",", ""))) < .000001
                except ValueError:
                    actual = False
            else:
                actual = q["submitted_answer"].strip().lower() == q["correct_answer"].strip().lower()
            require(actual == q["is_correct"], f"Practice answer mismatch: {q['id']}")
            maximum = max(maximum, combo)
            xp += award["xp_awarded"]
        require((s["questions_answered"], s["correct_answers"], s["xp_earned"], s["current_combo"], s["max_combo"]) == (len(rows), total, xp, combo, maximum), f"Practice session counters: {s['id']}")
    for m in p["practice_mastery"]:
        replayed = mastery.get((m["student_id"], m["competency_key"]))
        require(replayed is not None and all(m[k] == replayed[k] for k in ("mastery_score", "difficulty", "attempts", "correct_answers", "hints_used", "correct_streak", "incorrect_streak", "last_practiced_at", "next_review_at")), f"Mastery aggregate mismatch: {m['id']}")
    for s in p["arcade_sessions"]:
        require(s["score"] == s["correct_answers"] <= s["total_answers"] and s["sequence"] == s["total_answers"] + 1 and s["current_streak"] <= s["max_streak"] <= s["score"], f"Arcade counters: {s['id']}")
    for s in p["number_guess_sessions"]:
        require(s["total_guesses"] >= s["score"] and s["range_max"] == 100 + 50 * s["score"] and parse(s["expires_at"]) - parse(s["started_at"]) == dt.timedelta(seconds=60+15*s["score"]) and parse(s["completed_at"]) <= parse(s["expires_at"]), f"Number Guess counters: {s['id']}")
    quests = {(q["user_id"], q["quest_name"]): q for q in p["user_quests"]}
    catalog = {(q["grade_level"], q["quest_name"]) for q in builder.old["npc_quest_catalog"]}
    for r in p["npc_quest_reward_receipts"]:
        if r["score"] is None:
            require(r in builder.old["npc_quest_reward_receipts"] and r["xp_awarded"] == r["trophies_awarded"] == 0, "Changed legacy NPC receipt")
            continue
        q = quests.get((r["user_id"], r["quest_name"]))
        require(r["score"] == 5 and r["xp_awarded"] == 50 and r["trophies_awarded"] == 1 and q and q["quest_status"] == "success" and q["progress"] == 5, f"NPC reward mismatch: {r}")
        require((profiles[r["user_id"]]["grade_level"], r["quest_name"]) in catalog, "NPC grade mismatch")
    if errors:
        raise ValueError("Fixture validation failed:\n" + "\n".join(errors[:60]))
    grouped = collections.defaultdict(list)
    for h in histograms:
        grouped[h["phase"]].append(h)
    phases = {name: {"sessions": len(rows), "attempts": sum(r["students"] for r in rows), "mean": round(sum(r["mean"] * r["students"] for r in rows) / sum(r["students"] for r in rows), 4), "histogram": {str(n): sum(r["histogram"][str(n)] for r in rows) for n in range(6)}} for name, rows in grouped.items()}
    return {"passed": True, "session_histograms": histograms, "phases": phases, "daily_coverage": [str(d) for d in dates()]}


def write_package(builder, folder, snapshot_folder):
    folder.mkdir(parents=True, exist_ok=True)
    validation = validate(builder)
    tables = folder / "tables"
    tables.mkdir(exist_ok=True)
    hashes = {}
    for table, rows in builder.payload.items():
        path = tables / f"{table}.json"
        path.write_text(json.dumps(rows, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        hashes[str(path.relative_to(folder))] = hashlib.sha256(path.read_bytes()).hexdigest()
    manifests = {
        "deletes.json": builder.delete,
        "mastery_baselines.json": list(builder.base_mastery.values()),
        "profile_contributions.json": builder.profile_contributions,
        "validation.json": validation,
        "proposed_memberships.json": builder.payload["class_members"],
    }
    for name, content in manifests.items():
        path = folder / name
        path.write_text(json.dumps(content, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        hashes[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    manifest = {"schema_version": 1, "purpose": "Synthetic development and presentation fixtures; not measured learner outcomes", "seed_namespace": str(NAMESPACE), "teacher_id": TEACHER, "classes": CLASSES, "student_ids": sorted(builder.profiles), "snapshot_directory": str(snapshot_folder.resolve()), "range": {"quiz_start": "2026-10-12", "activity_start": "2026-10-26", "activity_end_inclusive": "2026-11-30", "timezone": "Asia/Manila"}, "future_records": "Nov23–30 are deliberately preloaded development fixtures. Reports through today must keep their normal date cutoff.", "table_counts": {t: len(r) for t, r in builder.payload.items()}, "delete_counts": {t: len(r) for t, r in builder.delete.items()}, "payload_sha256": hashes, "apply_contract": {"class_members": "insert the four proposed rows only after membership review", "quiz_sessions": "upsert scoped existing/new assignments; preserves existing IDs and source content", "questions": "insert new-session questions without id; use database bigint identity", "quiz_results": "replace exact scoped rows through normal server maintenance/lifecycle; no UPDATE immutability bypass", "quiz_session_students": "replace exact scoped eligibility rows; preserve retake columns and allowances", "quiz_participants": "replace exact scoped participation rows", "practice_sessions/questions": "delete exact scoped window IDs then insert generated rows; preserve all earlier raw rows", "practice_mastery": "upsert complete cohort aggregates", "arcade_sessions/number_guess_sessions": "delete exact window IDs then insert generated runs", "arcade_scores/number_guess_scores/arcade_achievements": "replace complete cohort aggregates from retained + new runs", "user_quests/npc_quest_reward_receipts": "replace exact scoped rows with payload; preserves legacy NULL-score zero-award receipts", "profiles": "partial update listed fields only after reward rows; never replace identity/auth data", "user_stats": "unchanged; separate PvP/squad gameplay is not fabricated", "audit_logs": "no generated authentication or security events"}, "legacy_practice_baseline": "Raw pre-Oct26 rows are preserved. Baseline mastery/difficulty uses their last persisted endpoint; attempt/correct/hints/streak counts replay retained answers. New questions exactly implement current SQL rewards/mastery rules.", "rollback": "Restore every changed scoped row from the immutable snapshot/backup and remove inserted keys listed in these payloads. Do not apply this package without a complete transaction and verified backup."}
    (folder / "manifest.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    (folder / "README.md").write_text("# Development presentation fixture package\n\nThese are synthetic test fixtures, not measured student outcomes. Provenance is retained in this package; no per-row label is added to application screens. The generator makes no network calls and cannot apply the package.\n\nThe package targets the reviewed 55 developer profiles and three classes only. It preserves source quiz/NPC question content, earlier raw practice, unrelated profile reward baselines, and legacy NPC receipt markers. No authentication/security audit events are invented. November ends on November 30. Future fixtures must remain excluded from reports whose cutoff is today.\n\nRead manifest.json, validation.json, profile_contributions.json, and deletes.json before applying. Back up original rows and run the import in one bounded server-maintenance transaction with its normal lifecycle guards. Keep authentication, integrity, RLS, and score-immutability guards enabled. The reviewed importer briefly suspends only the notification delivery bridge under a transaction lock to prevent historical receipt emails, then restores its original state before commit. The input snapshot was read nontransactionally; compare row fingerprints before any mutation.\n", encoding="utf-8")
    return manifest, validation


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    builder = FixtureBuilder(read_snapshot(args.snapshot)).build()
    manifest, validation = write_package(builder, args.output, args.snapshot)
    print(json.dumps({"passed": validation["passed"], "counts": manifest["table_counts"], "phases": validation["phases"]}, indent=2))


if __name__ == "__main__":
    main()
