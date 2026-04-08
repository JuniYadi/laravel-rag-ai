<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Document;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $user = auth()->user();

        abort_if(! $user, 403);

        $documentsQuery = Document::query()->where('user_id', $user->id);

        $metrics = [
            'total_documents' => (clone $documentsQuery)->count(),
            'documents_completed' => (clone $documentsQuery)->where('status', 'completed')->count(),
            'documents_pending' => (clone $documentsQuery)->whereIn('status', ['pending', 'processing'])->count(),
            'documents_failed' => (clone $documentsQuery)->where('status', 'failed')->count(),
            'chat_messages' => ChatMessage::query()->where('user_id', $user->id)->count(),
        ];

        $recentUploads = (clone $documentsQuery)
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'title', 'status', 'created_at']);

        $recentChats = ChatMessage::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'role', 'content', 'created_at']);

        return view('dashboard', [
            'metrics' => $metrics,
            'recentUploads' => $recentUploads,
            'recentChats' => $recentChats,
        ]);
    }
}
