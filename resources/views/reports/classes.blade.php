@extends('reports.layout')
@section('report-subtitle', 'Classroom Report')
@section('report-name', 'Classes')
@section('generated', $generated)
@section('report-content')
<div class="report-title">Classroom Report</div>
<div class="meta">Teacher: {{ trim(($teacher['last_name'] ?? '') . ', ' . ($teacher['first_name'] ?? ''), ', ') }} &nbsp;|&nbsp; Total Classes: {{ count($rows) }}</div>
<table>
    <thead>
        <tr>
            <th>Class Name</th>
            <th>Join Code</th>
            <th>Students</th>
            <th>Roster</th>
            <th>Created</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $r)
        <tr>
            <td><strong>{{ $r['class_name'] }}</strong></td>
            <td>{{ $r['join_code'] }}</td>
            <td class="text-center">{{ $r['students'] }}</td>
            <td style="font-size:9px; color:#64748b;">{{ $r['roster'] ?: '—' }}</td>
            <td>{{ $r['created'] }}</td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center">No classes found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
