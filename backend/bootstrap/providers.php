<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    Modules\IAM\Infrastructure\Providers\IamServiceProvider::class,
    Modules\Organization\Companies\Infrastructure\Providers\OrganizationServiceProvider::class,
];
