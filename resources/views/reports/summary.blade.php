@extends('reports.layout')
@section('report-subtitle', 'Platform Summary Report')
@section('report-name', 'Summary')
@section('generated', $summary['generated'])
@section('report-content')
<div class="report-title">Platform Summary Report</div>

<div class="summary-grid">
    <div class="summary-card">
        <div class="label">Total Users</div>
        <div class="value">{{ $summary['total_users'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Total Students</div>
        <div class="value">{{ $summary['total_students'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Total Teachers</div>
        <div class="value">{{ $summary['total_teachers'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Pending Teachers</div>
        <div class="value">{{ $summary['total_pending'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Total Quizzes</div>
        <div class="value">{{ $summary['total_quizzes'] }}</div>
    </div>
    <div class="summary-card">
        <div class="label">Total Attempts</div>
        <div class="value">{{ $summary['total_attempts'] }}</div>
    </div>
    <div class="summary-card" style="border-left-color:#bc13fe;">
        <div class="label">Platform Avg Accuracy</div>
        <div class="value" style="color:#bc13fe;">{{ $summary['avg_accuracy'] }}</div>
    </div>
</div>

<div class="report-title" style="margin-top:24px;">Top 10 Students by Trophies</div>
<table>
    <thead>
        <tr>
            <th>Rank</th>
            <th>Student Name</th>
            <th>Grade Level</th>
            <th>Trophies</th>
        </tr>
    </thead>
    <tbody>
        @forelse($top10 as $i => $s)
        <tr>
            <td class="text-center"><strong>#{{ $i + 1 }}</strong></td>
            <td><strong>{{ $s['name'] }}</strong></td>
            <td>{{ $s['grade'] }}</td>
            <td class="text-center"><strong>{{ $s['trophies'] }}</strong></td>
        </tr>
        @empty
        <tr><td colspan="4" class="text-center">No students found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection