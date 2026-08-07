# 12. Employee Management Module

The first functional module (roadmap Phase 1). It lets an **Admin** add, edit,
delete employees, assign a **department**, and assign/unassign a **computer**.

It uses a hybrid that fits the Livewire dashboard while honoring the classic
controller pattern:

- **`EmployeeController`** (resourceful) + **Form Requests** + **Policy** +
  **Blade forms** own all *mutations*.
- A **Livewire `EmployeeIndex`** component owns the *live table* (search,
  department filter, pagination, inline delete).

## 12.1 Delivered files

| File | Purpose |
| ---- | ------- |
| `app/Http/Controllers/EmployeeController.php` | Resourceful CRUD + assign/unassign computer |
| `app/Http/Requests/StoreEmployeeRequest.php` | Create validation (user + profile) |
| `app/Http/Requests/UpdateEmployeeRequest.php` | Update validation (unique-ignore, optional password) |
| `app/Http/Requests/AssignComputerRequest.php` | Computer assignment validation |
| `app/Policies/EmployeePolicy.php` | Authorization (auto-discovered) |
| `app/Livewire/Employees/EmployeeIndex.php` | Live searchable/paginated table |
| `routes/modules/employees.php` | Route definitions |
| `resources/views/employees/*.blade.php` | index, create, edit, show, `_form` partial |
| `resources/views/livewire/employees/employee-index.blade.php` | Livewire table view |

## 12.2 Routes

Include the module route file inside the authenticated group in
`routes/web.php`:

```php
Route::middleware(['auth', 'verified', 'active'])->group(function () {
    require __DIR__.'/modules/employees.php';
});
```

| Verb | URI | Name | Action |
| ---- | --- | ---- | ------ |
| GET | `/employees` | `employees.index` | List (Livewire table) |
| GET | `/employees/create` | `employees.create` | Create form |
| POST | `/employees` | `employees.store` | Store (creates user + employee) |
| GET | `/employees/{employee}` | `employees.show` | Profile + computers |
| GET | `/employees/{employee}/edit` | `employees.edit` | Edit form |
| PUT/PATCH | `/employees/{employee}` | `employees.update` | Update |
| DELETE | `/employees/{employee}` | `employees.destroy` | Soft-delete + disable login |
| POST | `/employees/{employee}/computers` | `employees.computers.assign` | Assign computer |
| DELETE | `/employees/{employee}/computers/{computer}` | `employees.computers.unassign` | Release computer |

## 12.3 Authorization

`EmployeeController::__construct()` calls `authorizeResource(Employee::class,
'employee')`, which maps each resource method to an `EmployeePolicy` ability:

| Method | Policy ability |
| ------ | -------------- |
| index | `viewAny` |
| show | `view` (admins, or the employee themselves) |
| create/store | `create` |
| edit/update | `update` |
| destroy | `delete` |
| assignComputer / unassignComputer | `assignComputer` (explicit `$this->authorize`) |

All abilities resolve through Spatie permissions (`manage employees`,
`manage computers`, `view own data`), so authorization is driven by the roles
seeded in [doc 11](11-authentication-authorization.md).

## 12.4 Validation highlights

- **Create** provisions the user account *and* the profile, so it validates
  `name/email/password/role` plus `employee_code/designation/department_id/
  phone/joined_on`. `email` is unique on `users`, `employee_code` unique on
  `employees`, `department_id` must `exist`.
- **Update** uses `Rule::unique(...)->ignore(...)` for email/code, and makes
  `password` `nullable` (blank = keep current).
- **Assign computer** requires an existing, non-soft-deleted `computer_id`.
- Password uses `Password::defaults()` so complexity rules are centrally
  configurable.

## 12.5 Key behaviors

- **Create** wraps user + role + employee creation in a `DB::transaction` so a
  failure can't leave an orphaned user.
- **Assign department** is part of the create/edit form (`department_id` select).
- **Assign computer** sets the chosen computer's `employee_id` (and `paired_at`
  if not already paired); **unassign** clears it, with a `404` guard so you
  can't detach another employee's computer.
- **Delete** is soft (employee `deleted_at`), and additionally **disables the
  login** (`is_active = false`) and **releases assigned computers** — all in one
  transaction. History (attendance, activity) is preserved.

## 12.6 Livewire table

`EmployeeIndex` uses `WithPagination`, URL-bound `#[Url] $search` (as `q`) and
`$department`, debounced live search, and an inline `delete()` that re-checks the
policy. Mutations that need forms (create/edit) link out to the controller,
keeping a single mutation path. It embeds into the Blade `index` page via
`<livewire:employees.employee-index />`.

## 12.7 Try it

```bash
php artisan migrate:fresh --seed        # roles + admin (doc 11)
php artisan serve
```

Sign in as `admin@treck.test` / `password`, go to `/employees`:
- **Add Employee** → fills user + profile, assigns role & department.
- Open a profile → **assign/unassign a computer**.
- Edit / delete from the profile or the table.
- Search and filter update the URL (shareable, back-button friendly).
