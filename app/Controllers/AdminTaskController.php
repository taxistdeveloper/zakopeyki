<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\HtmlSanitizer;
use App\Helpers\UploadHelper;
use App\Models\Notification;
use App\Models\OpsTask;
use App\Models\User;
use App\Services\OpsTaskService;

class AdminTaskController extends Controller
{
    public function index(): void
    {
        Auth::requireStaff();
        $model = new OpsTask();
        $filters = [
            'status' => (string) ($_GET['status'] ?? ''),
            'assignee_id' => isset($_GET['assignee']) && $_GET['assignee'] !== '' ? (int) $_GET['assignee'] : 0,
            'priority' => (string) ($_GET['priority'] ?? ''),
            'deadline' => (string) ($_GET['deadline'] ?? ''),
            'sort' => (string) ($_GET['sort'] ?? 'priority'),
        ];
        $viewMode = (string) ($_GET['view'] ?? 'list');
        if (!in_array($viewMode, ['list', 'kanban'], true)) {
            $viewMode = 'list';
        }

        $kanban = [];
        $tasks = [];
        if ($viewMode === 'kanban') {
            $kanbanFilters = $filters;
            $kanbanFilters['status'] = '';
            $kanban = $model->groupedByStatus($kanbanFilters);
        } else {
            $tasks = $model->search($filters);
        }

        $this->view('admin/tasks/index', $this->payload([
            'title' => t('admin.tasks'),
            'tasks' => $tasks,
            'kanban' => $kanban,
            'filters' => $filters,
            'viewMode' => $viewMode,
            'staff' => (new User())->listStaff(),
            'openCount' => $model->countOpen(),
        ]));
    }

    public function createForm(): void
    {
        Auth::requireStaff();
        $this->view('admin/tasks/form', $this->payload([
            'title' => t('admin.task_new'),
            'task' => null,
            'files' => [],
            'staff' => (new User())->listStaff(),
        ]));
    }

    public function store(): void
    {
        Auth::requireStaff();
        $parsed = $this->parseTaskPost();
        if ($parsed['error'] !== null) {
            $_SESSION['error'] = $parsed['error'];
            $this->redirect('/admin/tasks/new');
            return;
        }

        $model = new OpsTask();
        $actorId = (int) Auth::id();
        $id = $model->createTask([
            'title' => $parsed['title'],
            'description' => $parsed['description'],
            'priority' => $parsed['priority'],
            'status' => $parsed['status'],
            'assignee_id' => $parsed['assignee_id'],
            'created_by' => $actorId,
            'deadline' => $parsed['deadline'],
        ]);
        $model->addEvent($id, $actorId, 'created', ['title' => $parsed['title']]);
        if ($parsed['assignee_id'] !== null) {
            $model->addEvent($id, $actorId, 'assigned', ['assignee_id' => $parsed['assignee_id']]);
        }
        $this->storeUploadedFiles($model, $id, null, 'files');

        $task = $model->findWithPeople($id) ?? ['id' => $id, 'title' => $parsed['title'], 'assignee_id' => $parsed['assignee_id']];
        (new OpsTaskService())->notifyAssigned($task, $actorId);
        ActivityLogger::info('admin.task_create', 'Задача: ' . $parsed['title'], 'ops_task', $id);
        $_SESSION['flash'] = t('admin.task_created');
        $this->redirect('/admin/tasks/' . $id);
    }

    public function show(string $id): void
    {
        Auth::requireStaff();
        $model = new OpsTask();
        $task = $model->findWithPeople((int) $id);
        if (!$task) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('admin.task_not_found')]);
            return;
        }
        $files = $model->filesForTask((int) $id);
        $commentFiles = [];
        $taskFiles = [];
        foreach ($files as $file) {
            $cid = (int) ($file['comment_id'] ?? 0);
            if ($cid > 0) {
                $commentFiles[$cid][] = $file;
            } else {
                $taskFiles[] = $file;
            }
        }
        $this->view('admin/tasks/show', $this->payload([
            'title' => (string) $task['title'],
            'task' => $task,
            'comments' => $model->comments((int) $id),
            'events' => $model->events((int) $id),
            'files' => $taskFiles,
            'commentFiles' => $commentFiles,
            'staff' => (new User())->listStaff(),
        ]));
    }

    public function editForm(string $id): void
    {
        Auth::requireStaff();
        $model = new OpsTask();
        $task = $model->findWithPeople((int) $id);
        if (!$task) {
            $_SESSION['error'] = t('admin.task_not_found');
            $this->redirect('/admin/tasks');
            return;
        }
        $this->view('admin/tasks/form', $this->payload([
            'title' => t('admin.task_edit'),
            'task' => $task,
            'files' => array_values(array_filter(
                $model->filesForTask((int) $id),
                static fn (array $f): bool => empty($f['comment_id'])
            )),
            'staff' => (new User())->listStaff(),
        ]));
    }

    public function update(string $id): void
    {
        Auth::requireStaff();
        $model = new OpsTask();
        $taskId = (int) $id;
        $task = $model->findWithPeople($taskId);
        if (!$task) {
            $_SESSION['error'] = t('admin.task_not_found');
            $this->redirect('/admin/tasks');
            return;
        }
        $parsed = $this->parseTaskPost();
        if ($parsed['error'] !== null) {
            $_SESSION['error'] = $parsed['error'];
            $this->redirect('/admin/tasks/' . $taskId . '/edit');
            return;
        }

        $actorId = (int) Auth::id();
        $oldAssignee = $task['assignee_id'] !== null ? (int) $task['assignee_id'] : null;
        $oldStatus = (string) $task['status'];
        $oldPriority = (string) $task['priority'];
        $oldDeadline = $task['deadline'] !== null ? (string) $task['deadline'] : null;
        $deadlineChanged = $oldDeadline !== $parsed['deadline'];

        $model->updateTask($taskId, [
            'title' => $parsed['title'],
            'description' => $parsed['description'],
            'priority' => $parsed['priority'],
            'status' => $parsed['status'],
            'assignee_id' => $parsed['assignee_id'],
            'deadline' => $parsed['deadline'],
            'deadline_notified_at' => $deadlineChanged ? null : ($task['deadline_notified_at'] ?? null),
        ]);

        if ($oldStatus !== $parsed['status']) {
            $model->addEvent($taskId, $actorId, 'status', ['from' => $oldStatus, 'to' => $parsed['status']]);
        }
        if ($oldPriority !== $parsed['priority']) {
            $model->addEvent($taskId, $actorId, 'priority', ['from' => $oldPriority, 'to' => $parsed['priority']]);
        }
        if ($oldAssignee !== $parsed['assignee_id']) {
            $model->addEvent($taskId, $actorId, 'assigned', ['assignee_id' => $parsed['assignee_id']]);
        }
        if ($deadlineChanged) {
            $model->addEvent($taskId, $actorId, 'deadline', ['deadline' => $parsed['deadline']]);
        }
        $this->storeUploadedFiles($model, $taskId, null, 'files');

        $fresh = $model->findWithPeople($taskId) ?? $task;
        $service = new OpsTaskService();
        if ($oldAssignee !== $parsed['assignee_id'] && $parsed['assignee_id'] !== null) {
            $service->notifyAssigned($fresh, $actorId);
        }
        if ($oldStatus !== $parsed['status']) {
            $service->notifyStatus($fresh, $oldStatus, $parsed['status'], $actorId);
        }

        ActivityLogger::info('admin.task_update', 'Задача: ' . $parsed['title'], 'ops_task', $taskId);
        $_SESSION['flash'] = t('admin.task_updated');
        $this->redirect('/admin/tasks/' . $taskId);
    }

    public function updateStatus(string $id): void
    {
        Auth::requireStaff();
        $model = new OpsTask();
        $taskId = (int) $id;
        $task = $model->findWithPeople($taskId);
        $status = (string) ($_POST['status'] ?? '');
        $ajax = $this->wantsJson();
        if (!$task || !in_array($status, OpsTask::STATUSES, true)) {
            if ($ajax) {
                $this->json(['ok' => false, 'error' => t('admin.task_not_found')], 404);
            }
            $_SESSION['error'] = t('admin.task_not_found');
            $this->redirect('/admin/tasks');
            return;
        }
        $from = (string) $task['status'];
        if ($from !== $status) {
            $model->updateStatus($taskId, $status);
            $actorId = (int) Auth::id();
            $model->addEvent($taskId, $actorId, 'status', ['from' => $from, 'to' => $status]);
            $fresh = $model->findWithPeople($taskId) ?? $task;
            $fresh['status'] = $status;
            (new OpsTaskService())->notifyStatus($fresh, $from, $status, $actorId);
            ActivityLogger::info('admin.task_status', 'Статус задачи «' . $task['title'] . '»: ' . $from . ' → ' . $status, 'ops_task', $taskId);
        }
        if ($ajax) {
            $this->json(['ok' => true, 'status' => $status]);
        }
        $_SESSION['flash'] = t('admin.task_updated');
        $this->redirect('/admin/tasks/' . $taskId);
    }

    public function comment(string $id): void
    {
        Auth::requireStaff();
        $model = new OpsTask();
        $taskId = (int) $id;
        $task = $model->findWithPeople($taskId);
        if (!$task) {
            $_SESSION['error'] = t('admin.task_not_found');
            $this->redirect('/admin/tasks');
            return;
        }
        $body = trim((string) ($_POST['body'] ?? ''));
        if (mb_strlen($body) < 1 || mb_strlen($body) > 4000) {
            $_SESSION['error'] = t('admin.task_comment_len');
            $this->redirect('/admin/tasks/' . $taskId);
            return;
        }
        $actorId = (int) Auth::id();
        $commentId = $model->addComment($taskId, $actorId, $body);
        $model->addEvent($taskId, $actorId, 'commented', []);
        $this->storeUploadedFiles($model, $taskId, $commentId, 'files');
        $_SESSION['flash'] = t('admin.task_comment_added');
        $this->redirect('/admin/tasks/' . $taskId);
    }

    public function destroy(string $id): void
    {
        Auth::requireAdmin();
        $model = new OpsTask();
        $task = $model->findWithPeople((int) $id);
        if (!$task) {
            $_SESSION['error'] = t('admin.task_not_found');
            $this->redirect('/admin/tasks');
            return;
        }
        $model->deleteTask((int) $id);
        ActivityLogger::info('admin.task_delete', 'Удалена задача: ' . ($task['title'] ?? ''), 'ops_task', (int) $id);
        $_SESSION['flash'] = t('admin.task_deleted');
        $this->redirect('/admin/tasks');
    }

    public function download(string $id): void
    {
        Auth::requireStaff();
        $model = new OpsTask();
        $file = $model->findFile((int) $id);
        if (!$file) {
            http_response_code(404);
            exit;
        }
        $path = $model->storedPath((string) $file['stored_name']);
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        $mime = (string) ($file['mime'] ?: (UploadHelper::detectMime($path) ?? 'application/octet-stream'));
        $name = str_replace(['"', "\r", "\n"], '', (string) $file['original_name']);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        readfile($path);
        exit;
    }

    /** @return array{title:string,description:string,priority:string,status:string,assignee_id:?int,deadline:?string,error:?string} */
    private function parseTaskPost(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = HtmlSanitizer::clean((string) ($_POST['description'] ?? ''));
        $priority = (string) ($_POST['priority'] ?? 'medium');
        $status = (string) ($_POST['status'] ?? 'new');
        $assigneeRaw = (int) ($_POST['assignee_id'] ?? 0);
        $deadlineRaw = trim((string) ($_POST['deadline'] ?? ''));

        if (mb_strlen($title) < 2 || mb_strlen($title) > 240) {
            return $this->taskParse($title, $description, $priority, $status, null, null, t('admin.task_title_required'));
        }
        if (!in_array($priority, OpsTask::PRIORITIES, true)) {
            $priority = 'medium';
        }
        if (!in_array($status, OpsTask::STATUSES, true)) {
            $status = 'new';
        }

        $assigneeId = null;
        if ($assigneeRaw > 0) {
            $user = (new User())->find($assigneeRaw);
            $role = (string) ($user['role'] ?? '');
            if (!$user || ($role !== 'admin' && $role !== 'manager')) {
                return $this->taskParse($title, $description, $priority, $status, null, null, t('admin.task_assignee_invalid'));
            }
            $assigneeId = $assigneeRaw;
        }

        $deadline = null;
        if ($deadlineRaw !== '') {
            $normalized = str_replace('T', ' ', $deadlineRaw);
            $ts = strtotime($normalized);
            if ($ts === false) {
                return $this->taskParse($title, $description, $priority, $status, $assigneeId, null, t('admin.task_deadline_invalid'));
            }
            $deadline = date('Y-m-d H:i:s', $ts);
        }

        return $this->taskParse($title, $description, $priority, $status, $assigneeId, $deadline, null);
    }

    /** @return array{title:string,description:string,priority:string,status:string,assignee_id:?int,deadline:?string,error:?string} */
    private function taskParse(string $title, string $description, string $priority, string $status, ?int $assigneeId, ?string $deadline, ?string $error): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'status' => $status,
            'assignee_id' => $assigneeId,
            'deadline' => $deadline,
            'error' => $error,
        ];
    }

    private function storeUploadedFiles(OpsTask $model, int $taskId, ?int $commentId, string $field): void
    {
        $files = $this->normalizeFiles($field);
        foreach ($files as $file) {
            $result = UploadHelper::storeStaffFile($file, $model->storageDir());
            if (empty($result['ok'])) {
                continue;
            }
            $model->addFile(
                $taskId,
                $commentId,
                (string) $result['original'],
                (string) $result['stored'],
                $result['mime'] ?? null,
                (int) $result['size']
            );
        }
    }

    /** @return list<array{name:string,tmp_name:string,error:int,size:int}> */
    private function normalizeFiles(string $field): array
    {
        if (empty($_FILES[$field])) {
            return [];
        }
        $bag = $_FILES[$field];
        if (!is_array($bag['name'] ?? null)) {
            return [[
                'name' => (string) ($bag['name'] ?? ''),
                'tmp_name' => (string) ($bag['tmp_name'] ?? ''),
                'error' => (int) ($bag['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($bag['size'] ?? 0),
            ]];
        }
        $out = [];
        foreach ($bag['name'] as $i => $name) {
            $out[] = [
                'name' => (string) $name,
                'tmp_name' => (string) ($bag['tmp_name'][$i] ?? ''),
                'error' => (int) ($bag['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($bag['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    private function wantsJson(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** @param array<string, mixed> $extra */
    private function payload(array $extra): array
    {
        $n = new Notification();
        $uid = (int) Auth::id();
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['error']);
        return array_merge([
            'currentNav' => 'admin',
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $flash,
            'error' => $error,
            'isAdmin' => Auth::isAdmin(),
        ], $extra);
    }
}
