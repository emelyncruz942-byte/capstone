@extends('layouts.app')

@section('title', 'Privacy Policy')
@section('description', 'Read how MathVerse handles account, classroom, quiz, and application data for students, teachers, and administrators.')
@section('body-class', 'min-h-screen p-4 sm:p-6 lg:p-10')

@section('content')
<main class="relative z-20 mx-auto w-full max-w-4xl" aria-labelledby="privacy-title">
    <header class="mb-6 text-center">
        <a href="{{ route('login') }}" class="inline-block font-orbitron text-3xl font-black tracking-tighter text-white">
            MATH<span class="text-cyan-400">VERSE</span>
        </a>
        <p class="mt-2 text-[9px] font-bold uppercase tracking-[0.35em] text-slate-500">Public Information</p>
    </header>

    <article class="portal-frame relative p-6 sm:p-10">
        <div class="corner top-0 left-0 border-r-0 border-b-0" aria-hidden="true"></div>
        <div class="corner top-0 right-0 border-l-0 border-b-0" aria-hidden="true"></div>
        <div class="corner bottom-0 left-0 border-r-0 border-t-0" aria-hidden="true"></div>
        <div class="corner bottom-0 right-0 border-l-0 border-t-0" aria-hidden="true"></div>

        <div class="border-b border-cyan-500/20 pb-6">
            <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-cyan-400">Your information</p>
            <h1 id="privacy-title" class="mt-2 font-orbitron text-2xl font-black uppercase text-white sm:text-3xl">
                Privacy <span class="text-cyan-400">Policy</span>
            </h1>
            <p class="mt-3 text-xs text-slate-500">Last updated: <time datetime="2026-10-01">October 1, 2026</time></p>
        </div>

        <div class="mt-8 space-y-8 text-sm leading-7 text-slate-300">
            <section aria-labelledby="privacy-scope">
                <h2 id="privacy-scope" class="font-orbitron text-sm font-bold uppercase text-white">1. Scope</h2>
                <p class="mt-3">
                    This policy explains how MathVerse handles information when students, teachers, and administrators use the MathVerse website, classroom tools, learning activities, and connected quiz game. The school, teacher, or administrator that provides access may also have its own privacy rules.
                </p>
            </section>

            <section aria-labelledby="privacy-information">
                <h2 id="privacy-information" class="font-orbitron text-sm font-bold uppercase text-white">2. Information MathVerse handles</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 marker:text-cyan-400">
                    <li><strong class="text-white">Account and profile details:</strong> name, email address, account role, grade level, profile picture, account status, and authentication identifiers.</li>
                    <li><strong class="text-white">Classroom and learning records:</strong> class membership, quizzes and assignments, answers, scores, attempts and authorized retakes, progress, achievements, and related timestamps.</li>
                    <li><strong class="text-white">Content you provide:</strong> quizzes, questions, feedback, reports, and other information submitted through the platform.</li>
                    <li><strong class="text-white">Technical and security information:</strong> session information, browser or device details, network information used for security and rate limiting, error diagnostics, and security or administrative audit records.</li>
                    <li><strong class="text-white">Communication preferences:</strong> notification records, email delivery status, and browser notification subscription information when notifications are enabled.</li>
                </ul>
            </section>

            <section aria-labelledby="privacy-use">
                <h2 id="privacy-use" class="font-orbitron text-sm font-bold uppercase text-white">3. How information is used</h2>
                <p class="mt-3">MathVerse uses this information to:</p>
                <ul class="mt-3 list-disc space-y-2 pl-5 marker:text-cyan-400">
                    <li>create and secure accounts, confirm identity, and provide the correct student, teacher, or administrator access;</li>
                    <li>run classes, quizzes, practice activities, games, scoring, retakes, progress reports, and learning analytics;</li>
                    <li>send account, class, quiz, and security notices;</li>
                    <li>prevent abuse, investigate errors, keep audit records, and protect users and the platform; and</li>
                    <li>maintain and improve reliability, accessibility, and the learning experience.</li>
                </ul>
                <p class="mt-3">MathVerse is not designed to sell personal information or serve third-party behavioral advertising.</p>
            </section>

            <section aria-labelledby="privacy-sharing">
                <h2 id="privacy-sharing" class="font-orbitron text-sm font-bold uppercase text-white">4. When information is shared</h2>
                <p class="mt-3">
                    Student learning records are available only to authorized users, such as the student, the relevant teacher, and administrators with a legitimate platform role. Information may also be processed by service providers that support hosting, authentication, database and file storage, email, browser notifications, and real-time game connections. Those providers receive only the information needed to provide their service.
                </p>
                <p class="mt-3">
                    Information may be disclosed when required by law, to protect the safety or rights of users, or to investigate fraud, abuse, or a security incident.
                </p>
            </section>

            <section aria-labelledby="privacy-retention">
                <h2 id="privacy-retention" class="font-orbitron text-sm font-bold uppercase text-white">5. Storage, security, and retention</h2>
                <p class="mt-3">
                    MathVerse uses access controls, encrypted connections, protected server credentials, audit records, and other safeguards intended to protect information. No online service can guarantee absolute security. Information is kept only as long as needed for the educational, operational, security, and legal purposes described here. Schools or administrators may retain, archive, correct, or remove records according to their responsibilities and applicable requirements.
                </p>
            </section>

            <section aria-labelledby="privacy-choices">
                <h2 id="privacy-choices" class="font-orbitron text-sm font-bold uppercase text-white">6. Your choices and requests</h2>
                <p class="mt-3">
                    Users can review and update supported profile details in MathVerse and can disable browser notifications through their browser. To request access, correction, deletion, or help with an account or learning record, contact the teacher, school, or MathVerse administrator that provided the account. A request may require identity verification and may be limited where a record must be kept for security, classroom, or legal reasons.
                </p>
            </section>

            <section aria-labelledby="privacy-students">
                <h2 id="privacy-students" class="font-orbitron text-sm font-bold uppercase text-white">7. Student accounts</h2>
                <p class="mt-3">
                    MathVerse is an educational platform and may be used by children through a school, teacher, parent, or guardian. Student users should create and use accounts only with the supervision or permission required by their school and applicable rules, and should not submit personal information that is not needed for learning.
                </p>
            </section>

            <section aria-labelledby="privacy-updates">
                <h2 id="privacy-updates" class="font-orbitron text-sm font-bold uppercase text-white">8. Policy updates and contact</h2>
                <p class="mt-3">
                    This policy may be updated when MathVerse features or data practices change. The date at the top will show the latest revision. Questions or privacy requests should be directed to the teacher, school, or MathVerse administrator that manages your access so the request reaches the person responsible for the account.
                </p>
            </section>
        </div>
    </article>

    @include('partials.public-footer')
</main>
@endsection
