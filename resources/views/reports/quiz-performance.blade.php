@extends('reports.layout')
@section('report-subtitle', 'Teacher Quiz Performance Report')
@section('report-name', 'Quiz Performance')
@section('generated', $generated)
@section('report-content')
<div class="report-title">Quiz Performance Report</div>
<div class="meta">Teacher: {{ trim(($teacher['last_name'] ?? '') . ', ' . ($teacher['first_name'] ?? ''), ', ') }} &nbsp;|&nbsp; Total Quizzes: {{ count($rows) }}</div>
<table>
    <thead>
        <tr>
            <th>Topic</th>
            <th>Room Code</th>
            <th>Attempts</th>
            <th>Avg Accuracy</th>
            <th>Pass Rate</th>
            <th>Date Created</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $r)
        <tr>
            <td>{{ $r['topic'] }}</td>
            <td><strong>{{ $r['room_code'] }}</strong></td>
            <td class="text-center">{{ $r['attempts'] }}</td>
            <td class="text-center">{{ $r['avg_acc'] === null ? '—' : $r['avg_acc'] . '%' }}</td>
            <td class="text-center">{{ $r['pass_rate'] === null ? '—' : $r['pass_rate'] . '%' }}</td>
            <td>{{ $r['date'] }}</td>
        </tr>
        @empty
        <tr><td colspan="6" class="text-center">No quizzes found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
