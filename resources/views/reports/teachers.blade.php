@extends('reports.layout')
@section('report-subtitle', 'Teacher Registry Report')
@section('report-name', 'Teacher Registry')
@section('generated', $generated)
@section('report-content')
<div class="report-title">Teacher Registry Report</div>
<div class="meta">Total Teachers: {{ count($rows) }}</div>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Teacher Name</th>
            <th>Email</th>
            <th>Quizzes Created</th>
            <th>Date Joined</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $i => $r)
        <tr>
            <td class="text-center">{{ $i + 1 }}</td>
            <td><strong>{{ $r['name'] }}</strong></td>
            <td style="font-size:9px;">{{ $r['email'] }}</td>
            <td class="text-center">{{ $r['quizzes'] }}</td>
            <td>{{ $r['joined'] }}</td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center">No teachers found.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
