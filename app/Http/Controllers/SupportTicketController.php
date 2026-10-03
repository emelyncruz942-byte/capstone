<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    private const STATUSES = ['all', 'open', 'in_progress', 'resolved', 'closed'];

    private const CATEGORIES = ['bug', 'error', 'account', 'quiz', 'vr', 'accessibility', 'other'];

    public function __construct(private SupabaseService $supabase) {}

    public function index(Request $request)
    {
        $user = $this->requester();
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }

        $page = max(1, min(100000, (int) $request->query('page', 1)));
        $perPage = 20;
        $filters = [
            'reporter_id' => $user['id'],
            'order' => 'updated_at.desc,id.desc',
        ];
        if ($status !== 'all') {
            $filters['status'] = $status;
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

        return view('support-tickets.index', compact(
            'user',
            'tickets',
            'status',
            'page',
            'total',
            'totalPages',
            'ticketsReady',
        ));
    }

    public function store(Request $request)
    {
        $user = $this->requester();
        $request->merge([
            'subject' => $this->trimmedInput($request, 'subject'),
            'description' => $this->trimmedInput($request, 'description'),
            'page_url' => $this->trimmedInput($request, 'page_url'),
            'reference_id' => strtoupper($this->trimmedInput($request, 'reference_id')),
        ]);
        $validated = $request->validate([
            'category' => 'required|in:'.implode(',', self::CATEGORIES),
            'subject' => 'required|string|min:5|max:160',
            'description' => 'required|string|min:20|max:5000',
            'page_url' => 'nullable|string|max:2048|url:https',
            'reference_id' => ['nullable', 'string', 'regex:/^MV-[A-F0-9]{16}$/'],
        ]);

        $pageUrl = $this->nullIfBlank($validated['page_url'] ?? null);
        if ($pageUrl !== null) {
            $pageUrl = $this->normalizeMathVerseUrl($pageUrl);
            if ($pageUrl === null) {
                throw ValidationException::withMessages([
                    'page_url' => 'Enter a page address from mathmetaverse.space.',
                ]);
            }
        }

        $reporterName = trim(
            (string) ($user['first_name'] ?? '').' '.(string) ($user['last_name'] ?? '')
        );

        try {
            $result = $this->supabase->adminInsertResult('support_tickets', [
                'reporter_id' => $user['id'],
                'reporter_role' => $user['role'],
                'reporter_name' => $reporterName !== '' ? mb_substr($reporterName, 0, 200) : 'MathVerse user',
                'reporter_email' => mb_substr((string) ($user['email'] ?? ''), 0, 320),
                'category' => $validated['category'],
                'subject' => $validated['subject'],
                'description' => $validated['description'],
                'page_url' => $pageUrl,
                'reference_id' => $this->nullIfBlank($validated['reference_id'] ?? null),
            ]);
        } catch (\Throwable) {
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        $ticket = $result['data'][0] ?? null;
        if (($result['error'] ?? null) !== null || ! is_array($ticket) || ! isset($ticket['id'])) {
            return back()->withInput()->with(
                'error',
                'Your support ticket could not be submitted right now. Please try again shortly.',
            );
        }

        $this->supabase->audit($user, 'support_ticket.created', 'support_ticket', $ticket['id'], [
            'category' => $validated['category'],
            'reference_id' => $this->nullIfBlank($validated['reference_id'] ?? null),
        ]);

        return redirect("/support-tickets/{$ticket['id']}")
            ->with('success', 'Your support ticket was submitted.');
    }

    public function show(string $id)
    {
        $user = $this->requester();

        try {
            $result = $this->supabase->adminSelectResult('support_tickets', '*', [
                'id' => $id,
                'reporter_id' => $user['id'],
            ]);
        } catch (\Throwable) {
            $result = ['data' => [], 'error' => 'unavailable'];
        }

        if (($result['error'] ?? null) !== null) {
            return redirect('/support-tickets')
                ->with('error', 'Support tickets are temporarily unavailable. Please try again shortly.');
        }

        $ticket = $result['data'][0] ?? null;
        if (! is_array($ticket)) {
            return redirect('/support-tickets')->with('error', 'That support ticket was not found.');
        }

        return view('support-tickets.show', compact('user', 'ticket'));
    }

    /** @return array<string, mixed> */
    private function requester(): array
    {
        $user = session('supabase_user');
        abort_unless(
            is_array($user) && in_array($user['role'] ?? null, ['student', 'teacher'], true),
            403,
        );

        return $user;
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

    private function normalizeMathVerseUrl(string $value): ?string
    {
        $parts = parse_url($value);
        $canonical = parse_url((string) config('app.canonical_url'));
        if (! is_array($parts)
            || ! is_array($canonical)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? ''))
                !== strtolower((string) ($canonical['host'] ?? ''))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || $path[0] !== '/') {
            $path = '/';
        }

        // Query strings and fragments can contain recovery credentials or
        // other private context. They are never needed to identify a page.
        return rtrim((string) config('app.canonical_url'), '/').$path;
    }
}
