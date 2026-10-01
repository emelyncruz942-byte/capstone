@extends('layouts.app')

@section('title', 'Terms and Conditions')
@section('description', 'Read the terms for using MathVerse accounts, classrooms, quizzes, learning activities, and the connected quiz game.')
@section('body-class', 'min-h-screen p-4 sm:p-6 lg:p-10')

@section('content')
<main class="relative z-20 mx-auto w-full max-w-4xl" aria-labelledby="terms-title">
    <header class="mb-6 text-center">
        <a href="{{ route('login') }}" class="inline-block font-orbitron text-3xl font-black tracking-tighter text-white">
            MATH<span class="text-purple-400">VERSE</span>
        </a>
        <p class="mt-2 text-[9px] font-bold uppercase tracking-[0.35em] text-slate-500">Public Information</p>
    </header>

    <article class="portal-frame relative p-6 sm:p-10">
        <div class="corner top-0 left-0 border-r-0 border-b-0" aria-hidden="true"></div>
        <div class="corner top-0 right-0 border-l-0 border-b-0" aria-hidden="true"></div>
        <div class="corner bottom-0 left-0 border-r-0 border-t-0" aria-hidden="true"></div>
        <div class="corner bottom-0 right-0 border-l-0 border-t-0" aria-hidden="true"></div>

        <div class="border-b border-purple-500/20 pb-6">
            <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-purple-400">Platform rules</p>
            <h1 id="terms-title" class="mt-2 font-orbitron text-2xl font-black uppercase text-white sm:text-3xl">
                Terms and <span class="text-purple-400">Conditions</span>
            </h1>
            <p class="mt-3 text-xs text-slate-500">Last updated: <time datetime="2026-10-01">October 1, 2026</time></p>
        </div>

        <div class="mt-8 space-y-8 text-sm leading-7 text-slate-300">
            <section aria-labelledby="terms-acceptance">
                <h2 id="terms-acceptance" class="font-orbitron text-sm font-bold uppercase text-white">1. Acceptance and eligibility</h2>
                <p class="mt-3">
                    These terms apply when you access or use the MathVerse website, classroom tools, learning activities, or connected quiz game. By creating an account or using MathVerse, you agree to follow these terms and the <a href="{{ route('privacy-policy') }}" class="text-cyan-400 underline underline-offset-2 hover:text-white">Privacy Policy</a>. If a school manages your access, its rules also apply. Students must use MathVerse with any permission or supervision required by their school, parent, guardian, or applicable rules.
                </p>
            </section>

            <section aria-labelledby="terms-accounts">
                <h2 id="terms-accounts" class="font-orbitron text-sm font-bold uppercase text-white">2. Accounts and access</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 marker:text-purple-400">
                    <li>Provide accurate account information and keep it current.</li>
                    <li>Keep passwords and account access private. Do not share an account or use another person's account.</li>
                    <li>Use only the role and permissions assigned to you. Teacher applications and administrator access may require approval.</li>
                    <li>Promptly tell the responsible teacher or administrator if you believe an account has been accessed without permission.</li>
                </ul>
            </section>

            <section aria-labelledby="terms-use">
                <h2 id="terms-use" class="font-orbitron text-sm font-bold uppercase text-white">3. Acceptable use</h2>
                <p class="mt-3">You may use MathVerse for legitimate educational and classroom purposes. You must not:</p>
                <ul class="mt-3 list-disc space-y-2 pl-5 marker:text-purple-400">
                    <li>cheat, falsify scores, submit another person's work, or interfere with a quiz, retake, result, or classroom record;</li>
                    <li>harass another user, impersonate someone, or post harmful, unlawful, deceptive, or inappropriate content;</li>
                    <li>attempt to bypass access controls, probe for vulnerabilities, introduce malicious code, automate abusive requests, or disrupt the service;</li>
                    <li>collect or disclose another user's personal information without authorization; or</li>
                    <li>copy, resell, or commercially exploit MathVerse or another person's content without the required permission.</li>
                </ul>
            </section>

            <section aria-labelledby="terms-educators">
                <h2 id="terms-educators" class="font-orbitron text-sm font-bold uppercase text-white">4. Teacher and administrator responsibilities</h2>
                <p class="mt-3">
                    Teachers and administrators must use student information only for authorized educational and administrative purposes, assign access carefully, review quiz content before use, and follow their institution's policies and applicable requirements. They are responsible for the accuracy and suitability of quizzes, deadlines, grades, and other content they create or approve.
                </p>
            </section>

            <section aria-labelledby="terms-content">
                <h2 id="terms-content" class="font-orbitron text-sm font-bold uppercase text-white">5. Content and intellectual property</h2>
                <p class="mt-3">
                    You keep any rights you hold in quizzes, questions, profile images, or other content you submit. You must have permission to submit that content. You allow MathVerse to store, process, display, and share it with authorized users only as needed to operate, secure, and improve the platform. MathVerse's software, branding, interface, and original materials may not be copied or redistributed except as permitted by law or written authorization.
                </p>
            </section>

            <section aria-labelledby="terms-learning">
                <h2 id="terms-learning" class="font-orbitron text-sm font-bold uppercase text-white">6. Educational results</h2>
                <p class="mt-3">
                    MathVerse supports teaching and practice, but automated scores, suggestions, and reports should be reviewed by an authorized teacher where they affect classroom decisions. The platform does not guarantee a particular grade, learning outcome, or uninterrupted quiz session. Report suspected scoring or content errors to the responsible teacher or administrator.
                </p>
            </section>

            <section aria-labelledby="terms-availability">
                <h2 id="terms-availability" class="font-orbitron text-sm font-bold uppercase text-white">7. Availability and changes</h2>
                <p class="mt-3">
                    Features may be corrected, improved, replaced, or temporarily unavailable for maintenance, security, or circumstances outside MathVerse's control. Reasonable safeguards are used, but users and institutions should keep any records they are independently required to retain. Third-party services used for authentication, hosting, notifications, storage, or real-time connections may have their own terms.
                </p>
            </section>

            <section aria-labelledby="terms-enforcement">
                <h2 id="terms-enforcement" class="font-orbitron text-sm font-bold uppercase text-white">8. Suspension and termination</h2>
                <p class="mt-3">
                    Access may be limited or suspended to protect users, investigate misuse, comply with a school or legal requirement, or enforce these terms. When appropriate, contact the responsible administrator if you believe an account action was made in error. Obligations concerning privacy, authorized records, intellectual property, and misuse continue after access ends where applicable.
                </p>
            </section>

            <section aria-labelledby="terms-updates">
                <h2 id="terms-updates" class="font-orbitron text-sm font-bold uppercase text-white">9. Updates and contact</h2>
                <p class="mt-3">
                    These terms may be updated when MathVerse changes. The revision date above identifies the current version. Questions, account concerns, or reports of misuse should be directed to the teacher, school, or MathVerse administrator that manages your access.
                </p>
            </section>
        </div>
    </article>

    @include('partials.public-footer')
</main>
@endsection
