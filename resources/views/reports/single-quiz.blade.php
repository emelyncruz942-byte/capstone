@extends('reports.layout')
@section('report-subtitle', 'Individual Quiz Bee Report')
@section('report-name', 'Quiz Report')
@section('generated', now()->format('M d, Y h:i A'))
@section('report-content')

<div class="report-title">{{ $summary['topic'] }} — Quiz Report</div>

{{-- Summary cards --}}
<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Room Code</div>
        <div class="value" style="font-size:18px;">{{ $summary['room_code'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Teacher</div>
        <div class="value" style="font-size:14px; margin-top:6px;">{{ $summary['teacher'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Total Questions</div>
        <div class="value">{{ $summary['total_questions'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Class Students</div>
        <div class="value">{{ $summary['total_students'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Avg Attempt Accuracy</div>
        <div class="value" style="color:#00f2ff;">{{ $summary['avg_accuracy'] }}%</div>
    </div>
    <div class="summary-card">
        <div class="label">Pass Rate of Attempts (≥75%)</div>
        <div class="value" style="color:#22c55e;">{{ $summary['pass_rate'] }}%</div>
    </div>
    <div class="summary-card">
        <div class="label">Passed</div>
        <div class="value" style="color:#22c55e;">{{ $summary['passed'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Completed Attempts</div>
        <div class="value" style="color:#00f2ff;">{{ $summary['total_attempts'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Failed (&lt;75%)</div>
        <div class="value" style="color:#ef4444;">{{ $summary['failed'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Missed</div>
        <div class="value" style="color:#ef4444;">{{ $summary['missed'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Pending</div>
        <div class="value" style="color:#38bdf8;">{{ $summary['pending'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Excused</div>
        <div class="value" style="color:#a78bfa;">{{ $summary['excused'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Completion Rate</div>
        <div class="value" style="color:#f59e0b;">{{ $summary['completion_rate'] }}%</div>
    </div>
</div>

{{-- Questions --}}
@if(count($questions) > 0)
<div class="report-title" style="margin-top:24px;">Questions ({{ count($questions) }})</div>
<table>
    <thead>
        <tr>
            <th style="width:30px;">#</th>
            <th>Question</th>
            <th>Correct Answer</th>
        </tr>
    </thead>
    <tbody>
        @foreach($questions as $i => $q)
        <tr>
            <td class="text-center">{{ $i + 1 }}</td>
            <td>{{ $q['question'] }}</td>
            <td style="color:#22c55e; font-weight:700;">{{ $q['correct_answer_label'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Student results --}}
<div class="report-title" style="margin-top:24px;">Student Results</div>
<table>
    <thead>
        <tr>
            <th>Rank</th>
            <th>Student Name</th>
            <th>Grade</th>
            <th>Score</th>
            <th>Accuracy</th>
            <th>Status</th>
            <th>Date Taken</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $r)
        @php
            $statusColor = $r['status'] === 'Excused'
                ? '#a78bfa'
                : ($r['status'] === 'Pending'
                    ? '#38bdf8'
                    : ($r['status'] === 'Missed'
                        ? '#f59e0b'
                        : (($r['accuracy'] ?? 0) >= 75 ? '#22c55e' : '#ef4444')));
        @endphp
        <tr>
            <td class="text-center"><strong>{{ $r['rank'] === null ? '—' : '#' . $r['rank'] }}</strong></td>
            <td><strong>{{ $r['name'] }}</strong></td>
            <td>{{ $r['grade'] }}</td>
            <td class="text-center">{{ $r['score'] }}</td>
            <td class="text-center">{{ $r['accuracy'] === null ? '—' : $r['accuracy'] . '%' }}</td>
            <td style="color:{{ $statusColor }}; font-weight:700;">{{ $r['status'] }}</td>
            <td style="font-size:9px;">{{ $r['date'] }}</td>
        </tr>
        @empty
        <tr><td colspan="7" class="text-center">No students are currently enrolled in this class.</td></tr>
        @endforelse
    </tbody>
</table>

@endsection
