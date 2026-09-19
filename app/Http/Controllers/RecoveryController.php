<?php

namespace App\Http\Controllers;

use App\Services\SupabaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RecoveryController extends Controller
{
    public function __construct(private SupabaseService $supabase) {}

    public function index(Request $request)
    {
        $user = session('supabase_user');
        $admin = ($user['role'] ?? '') === 'admin';
        $type = (string) $request->query('type', 'class');
        abort_unless(in_array($type, $admin ? ['class', 'quiz', 'account'] : ['class', 'quiz'], true), 404);
        $page = max(1, min(100000, (int) $request->query('page', 1)));
        $search = trim(preg_replace('/[^\pL\pN\s@._-]/u', '', mb_substr((string) $request->query('search', ''), 0, 80)) ?? '');
        $column = $type === 'account' ? 'deactivated_at' : 'deleted_at';
        $table = ['class' => 'classes', 'quiz' => 'quizzes', 'account' => 'profiles'][$type];
        $filters = [$column => ['operator' => 'not.is', 'value' => 'null'], 'order' => $column.'.desc,id.asc'];
        if (!$admin) {
            $filters['teacher_id'] = $user['id'];
        } elseif ($type === 'quiz') {
            // Admin moderation of another creator is limited to shared quizzes.
            $filters['or'] = '(visibility.eq.shared,teacher_id.eq.'.$user['id'].')';
        } elseif ($type === 'account') {
            $filters['role'] = ['operator' => 'in', 'value' => '(student,teacher,pending_teacher)'];
        }
        if ($search !== '') {
            $field = ['class' => 'class_name', 'quiz' => 'topic', 'account' => 'email'][$type];
            $filters[$field] = ['operator' => 'ilike', 'value' => '*'.$search.'*'];
        }
        try {
            $result = $this->supabase->adminSelectPage($table, '*', $filters, 25, ($page - 1) * 25);
            $trashReady = ($result['error'] ?? null) === null;
        } catch (\Throwable $exception) {
            Log::warning('Trash could not be read.', ['exception' => $exception::class]);
            $result = ['data' => [], 'total' => 0];
            $trashReady = false;
        }
        $items = $result['data'];
        $pages = max(1, (int) ceil($result['total'] / 25));
        $activePage = 'trash';
        return view('recovery.index', compact('user', 'admin', 'type', 'search', 'page', 'pages', 'items', 'activePage', 'trashReady'));
    }

    public function restoreItem(Request $request, string $kind, string $id)
    {
        abort_unless(in_array($kind, ['class', 'quiz'], true), 404);
        $role = session('supabase_user.role');
        $result = $this->supabase->adminRpcResult('set_recovery_item', [
            'p_actor_id' => session('supabase_user.id'), 'p_kind' => $kind, 'p_id' => $id, 'p_restore' => true,
        ]);
        if ($result['error'] !== null || ($result['data'][0]['id'] ?? null) !== $id) {
            return redirect('/'.$role.'/trash?type='.$kind)->with('error', 'This record could not be restored. It may require administrator approval, or the latest database update.');
        }
        return redirect('/'.$role.'/trash?type='.$kind)->with('success', $kind === 'class'
            ? 'Class restored as archived, with its roster and history. Its teacher can reactivate it from Class Settings.'
            : 'Quiz restored with its questions and version history.');
    }

    public function deactivate(string $id)
    {
        $result = $this->supabase->adminRpcResult('set_account_deactivated', [
            'p_actor_id' => session('supabase_user.id'), 'p_id' => $id, 'p_restore' => false,
        ]);
        if ($result['error'] !== null || ($result['data'][0]['id'] ?? null) !== $id) {
            return redirect('/admin/dashboard')->with('error', 'The account could not be deactivated. No account was deleted. Check the latest database update.');
        }
        // The transaction has already blocked dashboard AND old JWT access.
        // A secondary Auth ban failure must never roll back that protection.
        try {
            $banned = $this->supabase->setAuthUserSuspended($id, true);
        } catch (\Throwable) {
            $banned = false;
        }
        if (!$banned) {
            Log::warning('Deactivated account still needs its secondary Auth ban.', ['user_id' => $id]);
        }
        return redirect('/admin/trash?type=account')->with('success', 'Account deactivated. Sign-in access is blocked and all records are preserved. You can reactivate it from Trash.');
    }

    public function reactivate(string $id)
    {
        $profile = $this->supabase->adminSelect('profiles', '*', ['id' => $id,
            'deactivated_at' => ['operator' => 'not.is', 'value' => 'null']])[0] ?? null;
        if (!$profile || !empty($profile['purge_intent_id'])) {
            return redirect('/admin/trash?type=account')->with('error', 'That account cannot currently be reactivated.');
        }
        $keepSuspended = (bool) ($profile['deactivation_was_suspended'] ?? false);
        if (!$keepSuspended && !$this->supabase->setAuthUserSuspended($id, false)) {
            return redirect('/admin/trash?type=account')->with('error', 'The account remains deactivated because sign-in access could not be restored.');
        }
        $result = $this->supabase->adminRpcResult('set_account_deactivated', [
            'p_actor_id' => session('supabase_user.id'), 'p_id' => $id, 'p_restore' => true,
        ]);
        if ($result['error'] !== null || ($result['data'][0]['id'] ?? null) !== $id) {
            if (!$keepSuspended) {
                $this->supabase->setAuthUserSuspended($id, true);
            }
            return redirect('/admin/trash?type=account')->with('error', 'The account could not be reactivated. It remains deactivated.');
        }
        return redirect('/admin/trash?type=account')->with('success', $keepSuspended
            ? 'Account reactivated, but its previous suspension is still in effect.'
            : 'Account reactivated. The user must sign in again; pending teachers still need approval.');
    }

    public function permanentlyDelete(Request $request, string $id)
    {
        $request->validate(['confirmation' => 'required|in:DELETE', 'delete_account_id' => 'required|in:'.$id]);
        $result = $this->supabase->adminRpcResult('prepare_account_purge', [
            'p_actor_id' => session('supabase_user.id'), 'p_id' => $id,
        ]);
        $intentId = $result['data'][0]['intent_id'] ?? null;
        if ($result['error'] !== null || !is_string($intentId) || !\Illuminate\Support\Str::isUuid($intentId)) {
            $ownsRecords = str_contains((string) $result['error'], 'still owns retained');
            return redirect('/admin/trash?type=account')->with('error', $ownsRecords
                ? 'Permanent deletion blocked: this account still owns classes or quizzes, including Trash. Keep it deactivated to preserve those records.'
                : 'Permanent deletion blocked. The account must be deactivated for seven days and have a working secure audit trail.');
        }
        try {
            $deleted = $this->supabase->deleteAuthUser($id);
        } catch (\Throwable) {
            $deleted = false;
        }
        if (!$deleted) {
            $this->supabase->adminRpcResult('cancel_account_purge', [
                'p_actor_id' => session('supabase_user.id'), 'p_id' => $id, 'p_intent_id' => $intentId,
            ]);
            return redirect('/admin/trash?type=account')->with('error', 'Permanent deletion did not complete. The account remains deactivated. Check the audit trail before retrying.');
        }
        $finalized = $this->supabase->completePrivilegedAudit($intentId, true, ['permanently_deleted' => true]);
        return redirect('/admin/trash?type=account')->with($finalized ? 'success' : 'error', $finalized
            ? 'Account permanently deleted. This cannot be undone from Trash.'
            : 'Account permanently deleted, but its audit outcome needs administrator attention. Do not repeat the deletion.');
    }
}
