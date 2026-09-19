@extends('reports.layout')
@section('report-subtitle', 'Personal Progress Report')
@section('report-name', 'Student Progress')
@section('generated', $summary['generated'])
@section('report-content')

<div class="report-title">Personal MathVerse Progress</div>
<div class="meta">Student: {{ $summary['student'] }} &nbsp;|&nbsp; {{ $summary['grade'] }}</div>

<div class="summary-grid">
    <div class="summary-card"><div class="label">Ended Assignments</div><div class="value">{{ $summary['ended'] }}</div></div>
    <div class="summary-card"><div class="label">Completed Attempts</div><div class="value">{{ $summary['attempts'] }}</div></div>
    <div class="summary-card"><div class="label">Passed</div><div class="value" style="color:#16a34a;">{{ $summary['passed'] }}</div></div>
    <div class="summary-card"><div class="label">Failed</div><div class="value" style="color:#dc2626;">{{ $summary['failed'] }}</div></div>
    <div class="summary-card"><div class="label">Missed</div><div class="value" style="color:#d97706;">{{ $summary['missed'] }}</div></div>
    <div class="summary-card"><div class="label">Excused</div><div class="value" style="color:#7c3aed;">{{ $summary['excused'] }}</div></div>
    <div class="summary-card"><div class="label">Average Attempt Accuracy</div><div class="value">{{ $summary['average'] === null ? '—' : $summary['average'] . '%' }}</div></div>
    <div class="summary-card"><div class="label">Best Accuracy</div><div class="value">{{ $summary['best'] === null ? '—' : $summary['best'] . '%' }}</div></div>
</div>

<div class="report-title" style="margin-top:24px;">Quiz History</div>
<table>
    <thead>
        <tr>
            <th>Quiz</th>
            <th>Class</th>
            <th>Room Code</th>
            <th>Score</th>
            <th>Accuracy</th>
            <th>Status</th>
            <th>Date Taken</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
            @php
                $statusColor = match ($row['status']) {
                    'Passed' => '#16a34a',
                    'Failed' => '#dc2626',
                    'Excused' => '#7c3aed',
                    default => '#d97706',
                };
            @endphp
            <tr>
                <td><strong>{{ $row['topic'] }}</strong></td>
                <td>{{ $row['class_name'] }}</td>
                <td>{{ $row['room_code'] }}</td>
                <td class="text-center">{{ $row['score'] }}</td>
                <td class="text-center">{{ $row['accuracy'] === null ? '—' : $row['accuracy'] . '%' }}</td>
                <td style="color:{{ $statusColor }}; font-weight:700;">{{ $row['status'] }}</td>
                <td>{{ $row['date'] }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center">No ended quiz assignments yet.</td></tr>
        @endforelse
    </tbody>
</table>

@endsection
