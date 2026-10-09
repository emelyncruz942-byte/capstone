@extends('layouts.app')

@section('title', 'VR Math Quiz Bee for Students and Teachers')
@section('description', 'Explore MathVerse, a VR math quiz bee and online classroom platform for students and teachers in the Philippines, with screen mode, games, and progress tracking.')
@section('body-class', 'min-h-screen p-4 sm:p-6 lg:p-10')

@section('content')
<main class="relative z-20 mx-auto w-full max-w-5xl" aria-labelledby="platform-title">
    <header class="mb-6 text-center">
        <a href="{{ route('login') }}" class="inline-block font-orbitron text-3xl font-black tracking-tighter text-white">
            MATH<span class="text-cyan-400">VERSE</span>
        </a>
        <p class="mt-2 text-[9px] font-bold uppercase tracking-[0.35em] text-slate-500">Interactive Mathematics Learning</p>
    </header>

    <article class="portal-frame relative overflow-hidden p-6 sm:p-10">
        <div class="corner top-0 left-0 border-r-0 border-b-0" aria-hidden="true"></div>
        <div class="corner top-0 right-0 border-l-0 border-b-0" aria-hidden="true"></div>
        <div class="corner bottom-0 left-0 border-r-0 border-t-0" aria-hidden="true"></div>
        <div class="corner bottom-0 right-0 border-l-0 border-t-0" aria-hidden="true"></div>

        <header class="border-b border-cyan-500/20 pb-8 text-center sm:text-left">
            <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-cyan-400">Learn, compete, and improve</p>
            <h1 id="platform-title" class="mt-3 font-orbitron text-3xl font-black uppercase leading-tight text-white sm:text-4xl">
                VR Math <span class="text-cyan-400">Quiz Bee</span> and Classroom Learning
            </h1>
            <p class="mt-5 max-w-3xl text-base leading-7 text-slate-300">
                Built as an online math learning platform for students and teachers in the Philippines, MathVerse lets students answer timed quizzes,
                build skills through math practice games, and join classroom activities managed by their teachers.
                Quiz takers can enter an immersive virtual-reality room or use standard screen mode without a VR headset.
            </p>
            <a href="{{ route('login') }}"
               class="mt-7 inline-flex min-h-11 items-center justify-center rounded border border-cyan-400/60 bg-cyan-400 px-6 py-3 font-orbitron text-xs font-black uppercase tracking-wider text-slate-950 transition hover:bg-cyan-300">
                Sign in or create an account
            </a>
        </header>

        <div class="mt-10 grid gap-6 md:grid-cols-3">
            <section class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-5" aria-labelledby="vr-mode-title">
                <h2 id="vr-mode-title" class="font-orbitron text-sm font-bold uppercase text-white">VR and screen modes</h2>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Join a multiplayer math quiz bee in VR or look around the quiz room and choose answers directly in standard screen mode.
                </p>
            </section>

            <section class="rounded-xl border border-purple-500/20 bg-purple-500/5 p-5" aria-labelledby="teacher-tools-title">
                <h2 id="teacher-tools-title" class="font-orbitron text-sm font-bold uppercase text-white">Classroom tools for teachers</h2>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Create and organize quizzes, assign activities to a class, manage quiz sessions and retakes, and review student results from one teacher portal.
                </p>
            </section>

            <section class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-5" aria-labelledby="student-practice-title">
                <h2 id="student-practice-title" class="font-orbitron text-sm font-bold uppercase text-white">Math practice for students</h2>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Practice mathematics through interactive questions and games, receive quiz results, and follow learning progress across classroom activities.
                </p>
            </section>
        </div>

        <section class="mt-12" aria-labelledby="how-it-works-title">
            <h2 id="how-it-works-title" class="font-orbitron text-xl font-black uppercase text-white">
                How the online math quiz platform works
            </h2>
            <ol class="mt-5 grid gap-4 sm:grid-cols-3">
                <li class="rounded-lg border border-white/10 bg-white/5 p-5 text-sm leading-6 text-slate-300">
                    <strong class="mb-2 block font-orbitron text-xs uppercase text-cyan-400">1. Join a class</strong>
                    Students use their MathVerse account and a teacher-provided class code to enter the right classroom.
                </li>
                <li class="rounded-lg border border-white/10 bg-white/5 p-5 text-sm leading-6 text-slate-300">
                    <strong class="mb-2 block font-orbitron text-xs uppercase text-purple-400">2. Take the quiz</strong>
                    When a teacher starts an assigned quiz, students choose VR mode or standard screen mode and answer before each timer ends.
                </li>
                <li class="rounded-lg border border-white/10 bg-white/5 p-5 text-sm leading-6 text-slate-300">
                    <strong class="mb-2 block font-orbitron text-xs uppercase text-emerald-400">3. Review progress</strong>
                    Scores and attempts are recorded so students can understand their results and teachers can support classroom learning.
                </li>
            </ol>
        </section>

        <section class="mt-12" aria-labelledby="faq-title">
            <h2 id="faq-title" class="font-orbitron text-xl font-black uppercase text-white">Frequently asked questions</h2>
            <div class="mt-5 space-y-4">
                <details class="rounded-lg border border-white/10 bg-white/5 p-5" open>
                    <summary class="cursor-pointer font-bold text-white">Do students need a VR headset to use MathVerse?</summary>
                    <p class="mt-3 text-sm leading-6 text-slate-300">
                        No. The quiz bee includes a standard screen mode, so students can look around the virtual room and select multiple-choice answers without a VR headset.
                    </p>
                </details>
                <details class="rounded-lg border border-white/10 bg-white/5 p-5">
                    <summary class="cursor-pointer font-bold text-white">Can teachers create and assign mathematics quizzes?</summary>
                    <p class="mt-3 text-sm leading-6 text-slate-300">
                        Yes. Approved teacher accounts can create quizzes, organize classes, assign quiz sessions, manage authorized retakes, and review results.
                    </p>
                </details>
                <details class="rounded-lg border border-white/10 bg-white/5 p-5">
                    <summary class="cursor-pointer font-bold text-white">Is MathVerse only a VR game?</summary>
                    <p class="mt-3 text-sm leading-6 text-slate-300">
                        No. MathVerse combines the VR math quiz bee with web-based classrooms, mathematics practice activities, learning games, and progress insights for students and teachers.
                    </p>
                </details>
            </div>
        </section>
    </article>

    @include('partials.public-footer')
</main>
@endsection
