@extends('reports.layout')
@section('report-subtitle', 'Platform Quiz Performance Report')
@section('report-name', 'Quiz Performance')
@section('generated', $generated)
@section('report-content')
<div class="report-title">Platform Quiz Performance</div>
<div class="meta">Class quiz assignments: {{ count($rows) }}</div>
<table>
    <thead><tr><th>Quiz</th><th>Class</th><th>Teacher</th><th>Code</th><th>Status</th><th>Questions</th><th>Attempts</th><th>Avg Accuracy</th><th>Pass Rate</th><th>Assigned</th></tr></thead>
    <tbody>
        @forelse($rows as $row)
        <tr>
            <td><strong>{{ $row['topic'] }}</strong></td>
            <td>{{ $row['class_name'] }}</td>
            <td>{{ $row['teacher'] }}</td>
            <td>{{ $row['room_code'] }}</td>
            <td>{{ $row['status'] }}</td>
            <td class="text-center">{{ $row['questions'] }}</td>
            <td class="text-center">{{ $row['attempts'] }}</td>
            <td class="text-center">{{ $row['avg_accuracy'] === null ? '—' : $row['avg_accuracy'] . '%' }}</td>
            <td class="text-center">{{ $row['pass_rate'] === null ? '—' : $row['pass_rate'] . '%' }}</td>
            <td>{{ $row['created'] }}</td>
        </tr>
        @empty
        <tr><td colspan="10" class="text-center">No class quiz assignments found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
