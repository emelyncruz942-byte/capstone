@extends('reports.layout')
@section('report-subtitle', 'Student Registry Report')
@section('report-name', 'Student Registry')
@section('generated', $generated)
@section('report-content')
<div class="report-title">Student Registry Report</div>
<div class="meta">Total Students: {{ count($rows) }}</div>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Student Name</th>
            <th>Email</th>
            <th>Grade</th>
            <th>Level</th>
            <th>Trophies</th>
            <th>Date Joined</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $i => $r)
        <tr>
            <td class="text-center">{{ $i + 1 }}</td>
            <td><strong>{{ $r['name'] }}</strong></td>
            <td style="font-size:9px;">{{ $r['email'] }}</td>
            <td>{{ $r['grade'] }}</td>
            <td class="text-center">{{ $r['level'] }}</td>
            <td class="text-center">{{ $r['trophies'] }}</td>
            <td>{{ $r['joined'] }}</td>
        </tr>
        @empty
        <tr><td colspan="7" class="text-center">No students found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection