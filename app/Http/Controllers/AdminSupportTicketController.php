<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminSupportTicketController extends Controller
{
    private const STATUSES = ['all', 'open', 'in_progress', 'resolved', 'closed'];

    private const PRIORITIES = ['all', 'low', 'normal', 'high', 'urgent'];

    public function __construct(private SupabaseService $supabase) {}

    public function index(Request $request)
    {
        $user = session('supabase_user');
        $status = $this->allowedFilter((string) $request->query('status', 'open'), self::STATUSES, 'open');
        $priority = $this->allowedFilter((string) $request->query('priority', 'all'), self::PRIORITIES, 'all');
        $page = max(1, min(100000, (int) $request->query('page', 1)));
        $perPage = 25;
        $filters = ['order' => 'updated_at.desc,id.desc'];
        if ($status !== 'all') {
            $filters['status'] = $status;
        }
        if ($priority !== 'all') {
            $filters['priority'] = $priority;
        }

        try {
            $result = $this->supabase->adminSelectPage(
                'support_tickets',
                '*',
                $filters,
                $perPage,
                ($page - 1) * $perPage,
            );
        } catch (\Throwable) {
            $result = ['data' => [], 'total' => 0, 'error' => 'unavailable'];
        }

        $tickets = $result['data'];
        $total = (int) $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        $ticketsReady = ($result['error'] ?? null) === null;

        if ($ticketsReady && $total > 0 && $page > $totalPages) {
            return redirect()->to($request->fullUrlWithQuery(['page' => $totalPages]));
        }

        return view('admin.support-tickets.index', compact(
            'user',
            'tickets',
            'status',
            'priority',
            'page',
            'total',
            'totalPages',
            'ticketsReady',
        ));
    }

    public function show(string $id)
    {
        $user = session('supabase_user');

        try {
            $result = $this->supabase->adminSelectResult('support_tickets', '*', ['id' => $id]);
        } catch (\Throwable) {
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        if (($result['error'] ?? null) !== null) {
            return redirect('/admin/support-tickets')
                ->with('error', 'Support tickets are temporarily unavailable. Please try again shortly.');
        }

        $ticket = $result['data'][0] ?? null;
        if (! is_array($ticket)) {
            return redirect('/admin/support-tickets')->with('error', 'That support ticket was not found.');
        }

        return view('admin.support-tickets.show', compact('user', 'ticket'));
    }

    public function update(Request $request, string $id)
    {
        $request->merge([
            'admin_response' => $this->trimmedInput($request, 'admin_response'),
        ]);
        $validated = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
            'priority' => 'required|in:low,normal,high,urgent',
            'admin_response' => 'nullable|string|max:3000',
            'lock_version' => 'required|integer|min:1|max:2147483646',
        ]);
        $admin = session('supabase_user');

        try {
            $read = $this->supabase->adminSelectResult('support_tickets', '*', ['id' => $id]);
        } catch (\Throwable) {
            $read = ['data' => [], 'error' => 'unavailable'];
        }
        if (($read['error'] ?? null) !== null) {
            return back()->with('error', 'The support ticket could not be loaded. Please try again shortly.');
        }

        $ticket = $read['data'][0] ?? null;
        if (! is_array($ticket)) {
            return redirect('/admin/support-tickets')->with('error', 'That support ticket was not found.');
        }

        $submittedResponse = $this->nullIfBlank($validated['admin_response'] ?? null);
        $finalResponse = $submittedResponse ?? $this->nullIfBlank($ticket['admin_response'] ?? null);
        if (in_array($validated['status'], ['resolved', 'closed'], true) && $finalResponse === null) {
            throw ValidationException::withMessages([
                'admin_response' => 'Write a response before resolving or closing this ticket.',
            ]);
        }

        try {
            $updated = $this->supabase->adminUpdate('support_tickets', [
                'status' => $validated['status'],
                'priority' => $validated['priority'],
                'admin_response' => $finalResponse,
                'assigned_to' => $validated['status'] === 'open'
                    ? ($ticket['assigned_to'] ?? null)
                    : $admin['id'],
                'updated_by' => $admin['id'],
            ], [
                'id' => $id,
                'lock_version' => (int) $validated['lock_version'],
            ]);
        } catch (\Throwable) {
            $updated = [];
        }

        if (! isset($updated[0]['id'])) {
            return back()->with(
                'error',
                'This ticket changed in another window or could not be saved. Reload it and try again.',
            );
        }

        $this->supabase->audit($admin, 'support_ticket.updated', 'support_ticket', $id, [
            'status' => $validated['status'],
            'priority' => $validated['priority'],
        ]);

        return redirect("/admin/support-tickets/{$id}")
            ->with('success', 'Support ticket updated.');
    }

    /** @param list<string> $allowed */
    private function allowedFilter(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function trimmedInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }

    private function nullIfBlank(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
