<?php

namespace App\Modules\Ppr\Models;

use App\Companies\BelongsToCompany;
use App\Models\User;
use App\Modules\Ppr\Database\Factories\ProjectMemberFactory;
use App\Modules\Ppr\Enums\ProjectRole;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's membership in one project, with their role there.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property ProjectRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(ProjectMemberFactory::class)]
class ProjectMember extends Model
{
    /** @use HasFactory<ProjectMemberFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = ['role'];

    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
