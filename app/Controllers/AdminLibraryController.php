<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\HtmlSanitizer;
use App\Helpers\UploadHelper;
use App\Models\Library;
use App\Models\Notification;

class AdminLibraryController extends Controller
{
    public function index(): void
    {
        Auth::requireStaff();
        $model = new Library();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'category_id' => (int) ($_GET['category'] ?? 0),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ];
        $result = $model->searchDocuments($filters, $page, 20);

        $this->view('admin/library/index', $this->payload([
            'title' => t('admin.library'),
            'categories' => $model->listCategories(),
            'documents' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'canManage' => Auth::isAdmin(),
        ]));
    }

    public function categoryCreate(): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $error = $this->validateCategory($model, $name, $parentId > 0 ? $parentId : null, null);
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/admin/library');
            return;
        }
        $id = $model->createCategory($name, $parentId > 0 ? $parentId : null);
        ActivityLogger::info('admin.library_category_create', 'Категория библиотеки: ' . $name, 'library_category', $id);
        $_SESSION['flash'] = t('admin.library_cat_created');
        $this->redirect('/admin/library');
    }

    public function categoryUpdate(string $id): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $catId = (int) $id;
        $category = $model->findCategory($catId);
        if (!$category) {
            $_SESSION['error'] = t('admin.library_cat_not_found');
            $this->redirect('/admin/library');
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $resolvedParent = $parentId > 0 ? $parentId : null;
        if ($resolvedParent === $catId) {
            $_SESSION['error'] = t('admin.library_cat_parent_self');
            $this->redirect('/admin/library');
            return;
        }
        $error = $this->validateCategory($model, $name, $resolvedParent, $catId);
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/admin/library');
            return;
        }
        $sort = (int) ($category['sort_order'] ?? 0);
        $model->updateCategory($catId, $name, $resolvedParent, $sort);
        ActivityLogger::info('admin.library_category_update', 'Категория библиотеки: ' . $name, 'library_category', $catId);
        $_SESSION['flash'] = t('admin.library_cat_updated');
        $this->redirect('/admin/library');
    }

    public function categoryDelete(string $id): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $catId = (int) $id;
        $category = $model->findCategory($catId);
        if (!$category) {
            $_SESSION['error'] = t('admin.library_cat_not_found');
            $this->redirect('/admin/library');
            return;
        }
        $full = null;
        foreach ($model->listCategories() as $row) {
            if ((int) $row['id'] === $catId) {
                $full = $row;
                break;
            }
        }
        if ($full && ((int) ($full['doc_count'] ?? 0) > 0 || (int) ($full['child_count'] ?? 0) > 0)) {
            $_SESSION['error'] = t('admin.library_cat_in_use');
            $this->redirect('/admin/library');
            return;
        }
        $model->deleteCategory($catId);
        ActivityLogger::info('admin.library_category_delete', 'Удалена категория библиотеки: ' . ($category['name'] ?? ''), 'library_category', $catId);
        $_SESSION['flash'] = t('admin.library_cat_deleted');
        $this->redirect('/admin/library');
    }

    public function createForm(): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $this->view('admin/library/form', $this->payload([
            'title' => t('admin.library_new'),
            'document' => null,
            'files' => [],
            'categories' => $model->listCategories(),
            'prefillCategory' => (int) ($_GET['category'] ?? 0),
        ]));
    }

    public function store(): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $parsed = $this->parseDocumentPost($model);
        if ($parsed['error'] !== null) {
            $_SESSION['error'] = $parsed['error'];
            $this->redirect('/admin/library/new');
            return;
        }
        $id = $model->createDocument(
            $parsed['category_id'],
            $parsed['title'],
            $parsed['body'],
            $parsed['tags'],
            (int) Auth::id()
        );
        $this->storeUploadedFiles($model, $id, 'files');
        ActivityLogger::info('admin.library_create', 'Документ библиотеки: ' . $parsed['title'], 'library_document', $id);
        $_SESSION['flash'] = t('admin.library_created');
        $this->redirect('/admin/library/' . $id);
    }

    public function show(string $id): void
    {
        Auth::requireStaff();
        $model = new Library();
        $doc = $model->findDocument((int) $id);
        if (!$doc) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('admin.library_not_found')]);
            return;
        }
        $this->view('admin/library/show', $this->payload([
            'title' => (string) $doc['title'],
            'document' => $doc,
            'files' => $model->filesForDocument((int) $id),
            'canManage' => Auth::isAdmin(),
        ]));
    }

    public function editForm(string $id): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $doc = $model->findDocument((int) $id);
        if (!$doc) {
            $_SESSION['error'] = t('admin.library_not_found');
            $this->redirect('/admin/library');
            return;
        }
        $this->view('admin/library/form', $this->payload([
            'title' => t('admin.library_edit'),
            'document' => $doc,
            'files' => $model->filesForDocument((int) $id),
            'categories' => $model->listCategories(),
            'prefillCategory' => (int) $doc['category_id'],
        ]));
    }

    public function update(string $id): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $docId = (int) $id;
        if (!$model->findDocument($docId)) {
            $_SESSION['error'] = t('admin.library_not_found');
            $this->redirect('/admin/library');
            return;
        }
        $parsed = $this->parseDocumentPost($model);
        if ($parsed['error'] !== null) {
            $_SESSION['error'] = $parsed['error'];
            $this->redirect('/admin/library/' . $docId . '/edit');
            return;
        }
        $model->updateDocument(
            $docId,
            $parsed['category_id'],
            $parsed['title'],
            $parsed['body'],
            $parsed['tags'],
            (int) Auth::id()
        );
        $this->storeUploadedFiles($model, $docId, 'files');
        ActivityLogger::info('admin.library_update', 'Документ библиотеки: ' . $parsed['title'], 'library_document', $docId);
        $_SESSION['flash'] = t('admin.library_updated');
        $this->redirect('/admin/library/' . $docId);
    }

    public function destroy(string $id): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $doc = $model->findDocument((int) $id);
        if (!$doc) {
            $_SESSION['error'] = t('admin.library_not_found');
            $this->redirect('/admin/library');
            return;
        }
        $model->deleteDocument((int) $id);
        ActivityLogger::info('admin.library_delete', 'Удалён документ библиотеки: ' . ($doc['title'] ?? ''), 'library_document', (int) $id);
        $_SESSION['flash'] = t('admin.library_deleted');
        $this->redirect('/admin/library');
    }

    public function deleteFile(string $id): void
    {
        $this->requireSuperAdmin();
        $model = new Library();
        $file = $model->findFile((int) $id);
        if (!$file) {
            $_SESSION['error'] = t('admin.library_file_not_found');
            $this->redirect('/admin/library');
            return;
        }
        $docId = (int) $file['document_id'];
        $model->deleteFile((int) $id);
        $_SESSION['flash'] = t('admin.library_file_deleted');
        $this->redirect('/admin/library/' . $docId . '/edit');
    }

    public function download(string $id): void
    {
        Auth::requireStaff();
        $model = new Library();
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

    private function requireSuperAdmin(): void
    {
        Auth::requireAdmin();
    }

    private function validateCategory(Library $model, string $name, ?int $parentId, ?int $exceptId): ?string
    {
        $len = mb_strlen($name);
        if ($len < 2 || $len > 180) {
            return t('admin.library_cat_name_len');
        }
        if ($parentId !== null) {
            $parent = $model->findCategory($parentId);
            if (!$parent) {
                return t('admin.library_cat_not_found');
            }
            if ($model->categoryDepth($parentId) >= Library::MAX_DEPTH) {
                return t('admin.library_cat_max_depth');
            }
            if ($exceptId !== null) {
                $descendants = $model->categoryAndDescendantIds($exceptId);
                if (in_array($parentId, $descendants, true)) {
                    return t('admin.library_cat_parent_self');
                }
            }
        }
        if ($model->categoryNameExists($name, $exceptId, $parentId)) {
            return t('admin.library_cat_exists');
        }
        return null;
    }

    /** @return array{title:string,body:string,tags:string,category_id:int,error:?string} */
    private function parseDocumentPost(Library $model): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $tags = trim((string) ($_POST['tags'] ?? ''));
        $body = HtmlSanitizer::clean((string) ($_POST['body'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 240) {
            return ['title' => $title, 'body' => $body, 'tags' => $tags, 'category_id' => $categoryId, 'error' => t('admin.library_title_len')];
        }
        if (mb_strlen($tags) > 500) {
            return ['title' => $title, 'body' => $body, 'tags' => $tags, 'category_id' => $categoryId, 'error' => t('admin.library_tags_len')];
        }
        if (!$model->findCategory($categoryId)) {
            return ['title' => $title, 'body' => $body, 'tags' => $tags, 'category_id' => $categoryId, 'error' => t('admin.library_cat_required')];
        }
        return ['title' => $title, 'body' => $body, 'tags' => $tags, 'category_id' => $categoryId, 'error' => null];
    }

    private function storeUploadedFiles(Library $model, int $documentId, string $field): void
    {
        $files = $this->normalizeFiles($field);
        foreach ($files as $file) {
            $result = UploadHelper::storeStaffFile($file, $model->storageDir());
            if (empty($result['ok'])) {
                if (($result['error'] ?? '') === 'empty') {
                    continue;
                }
                continue;
            }
            $model->addFile(
                $documentId,
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
