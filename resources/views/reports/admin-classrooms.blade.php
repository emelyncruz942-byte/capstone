@extends('reports.layout')
@section('report-subtitle', 'Platform Classroom Activity Report')
@section('report-name', 'Classroom Activity')
@section('generated', $generated)
@section('report-content')
<div class="report-title">Platform Classroom Activity</div>
<div class="meta">Classrooms: {{ count($rows) }}</div>
<table>
    <thead><tr><th>Class</th><th>Teacher</th><th>Grade</th><th>Status</th><th>Students</th><th>Assignments</th><th>Attempts</th><th>Avg Accuracy</th><th>Created</th></tr></thead>
    <tbody>
        @forelse($rows as $row)
        <tr>
            <td><strong>{{ $row['class_name'] }}</strong></td>
            <td>{{ $row['teacher'] }}</td>
            <td>{{ $row['grade'] }}</td>
            <td>{{ $row['status'] }}</td>
            <td class="text-center">{{ $row['students'] }}</td>
            <td class="text-center">{{ $row['assignments'] }}</td>
            <td class="text-center">{{ $row['attempts'] }}</td>
            <td class="text-center">{{ $row['avg_accuracy'] === null ? '—' : $row['avg_accuracy'] . '%' }}</td>
            <td>{{ $row['created'] }}</td>
        </tr>
        @empty
        <tr><td colspan="9" class="text-center">No classrooms found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
