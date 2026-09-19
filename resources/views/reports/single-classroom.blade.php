@extends('reports.layout')
@section('report-subtitle', 'Individual Classroom Report')
@section('report-name', 'Classroom Report')
@section('generated', now()->format('M d, Y h:i A'))
@section('report-content')

<div class="report-title">{{ $summary['class_name'] }} — Classroom Report</div>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Join Code</div>
        <div class="value" style="font-size:18px;">{{ $summary['join_code'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Teacher</div>
        <div class="value" style="font-size:14px; margin-top:6px;">{{ $summary['teacher'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Total Students</div>
        <div class="value">{{ $summary['total_students'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Class Avg Accuracy</div>
        <div class="value" style="color:#00f2ff;">{{ $summary['avg_accuracy'] }}%</div>
    </div>
    <div class="summary-card">
        <div class="label">Date Created</div>
        <div class="value" style="font-size:14px; margin-top:6px;">{{ $summary['created'] }}</div>
    </div>
</div>

<div class="report-title" style="margin-top:24px;">Student Roster</div>
<table>
    <thead>
        <tr>
            <th>Rank</th>
            <th>Student Name</th>
            <th>Grade</th>
            <th>Level</th>
            <th>Trophies</th>
            <th>Quizzes</th>
            <th>Avg Accuracy</th>
            <th>Date Joined</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $i => $r)
        @php
            $accColor = $r['avg_acc'] === null
                ? '#64748b'
                : ($r['avg_acc'] >= 75 ? '#22c55e' : '#ef4444');
        @endphp
        <tr>
            <td class="text-center"><strong>#{{ $i + 1 }}</strong></td>
            <td><strong>{{ $r['name'] }}</strong></td>
            <td>{{ $r['grade'] }}</td>
            <td class="text-center">{{ $r['level'] }}</td>
            <td class="text-center">{{ $r['trophies'] }}</td>
            <td class="text-center">{{ $r['quizzes'] }}</td>
            <td class="text-center" style="color:{{ $accColor }}; font-weight:700;">
                {{ $r['avg_acc'] === null ? '—' : $r['avg_acc'] . '%' }}
            </td>
            <td>{{ $r['joined'] }}</td>
        </tr>
        @empty
        <tr><td colspan="8" class="text-center">No students enrolled yet.</td></tr>
        @endforelse
    </tbody>
</table>

@endsection
