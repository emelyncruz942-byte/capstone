@extends('reports.layout')
@section('report-subtitle', 'Student Progress Report')
@section('report-name', 'Student Progress')
@section('generated', $generated)
@section('report-content')
<div class="report-title">Student Progress Report</div>
<div class="meta">Teacher: {{ trim(($teacher['last_name'] ?? '') . ', ' . ($teacher['first_name'] ?? ''), ', ') }} &nbsp;|&nbsp; Total Students: {{ count($rows) }}</div>
<table>
    <thead>
        <tr>
            <th>Student Name</th>
            <th>Grade Level</th>
            <th>Quizzes Taken</th>
            <th>Avg Accuracy</th>
            <th>Trophies</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $r)
        <tr>
            <td><strong>{{ $r['name'] }}</strong></td>
            <td>{{ $r['grade'] }}</td>
            <td class="text-center">{{ $r['quizzes'] }}</td>
            <td class="text-center">{{ $r['avg_acc'] === null ? '—' : $r['avg_acc'] . '%' }}</td>
            <td class="text-center">{{ $r['trophies'] }}</td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center">No students found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
