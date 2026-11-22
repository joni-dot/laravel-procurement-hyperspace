<?php

namespace App\Console\Commands;

use App\Actions\Companies\CreateCompany as CreateCompanyAction;
use App\Actions\Companies\CreateDemoCompany;
use App\Concerns\CompanyValidationRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Sets up a company from the command line, the same way the platform pages do. Meant for
 * self-hosters, who may not have a platform admin or working email yet.
 */
class CreateCompany extends Command
{
    use CompanyValidationRules;

    protected $signature = 'app:create-company
        {name : The company name, as its members see it}
        {slug : The company address: /{slug}/... (lowercase letters, digits and dashes)}
        {admin-email : Who becomes the company\'s first admin; they get an invitation}
        {--admin-name= : The first admin\'s name (default: the part of the email before the @)}
        {--demo : Fill the company with made-up suppliers and a project, and mark it as a demo}';

    protected $description = 'Create a company and invite its first admin';

    public function handle(CreateCompanyAction $createCompany, CreateDemoCompany $createDemoCompany): int
    {
        $email = Str::lower(trim((string) $this->argument('admin-email')));

        $input = [
            'name' => trim((string) $this->argument('name')),
            'slug' => Str::lower(trim((string) $this->argument('slug'))),
            'admin_name' => trim((string) ($this->option('admin-name') ?? Str::before($email, '@'))),
            'admin_email' => $email,
        ];

        $validator = Validator::make($input, [...$this->companyRules(), ...$this->firstAdminRules()], [], $this->companyAttributes());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $invitation = $this->option('demo')
            ? $createDemoCompany->handle($input['name'], $input['slug'], $input['admin_name'], $email)
            : $createCompany->handle($input['name'], $input['slug'], $input['admin_name'], $email);

        $this->components->info(($this->option('demo') ? 'Demo company' : 'Company')." {$invitation->company->name} created at ".url($invitation->company->slug).'.');
        $this->components->info("An invitation to be its admin was emailed to {$email}.");
        $this->line('  If email isn\'t set up yet, pass this link on to them yourself. Treat it like a password:');
        $this->line('  whoever has it can accept the invitation, so send it only to that person.');
        $this->line('  '.route('invitation.show', ['token' => $invitation->plainInvitationToken]));

        return self::SUCCESS;
    }
}
