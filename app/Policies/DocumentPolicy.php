<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return (int) $document->user_id === (int) $user->id;
    }

    public function delete(User $user, Document $document): bool
    {
        return (int) $document->user_id === (int) $user->id;
    }
}
