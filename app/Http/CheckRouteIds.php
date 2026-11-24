<?php

namespace App\Http;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;

/**
 * Runs before Laravel looks up the records named in an address (route model binding). A record found
 * by its numeric id can only have an id the database can hold, so any other value is "not found"
 * straight away, without asking the database: PostgreSQL would answer a value too large for its
 * bigint ids, or one that isn't a number, with an error instead of no record.
 *
 * Checked here once for every route, so a new route or model needs nothing of its own.
 */
final class CheckRouteIds
{
    public function __invoke(Container $container, Route $route, Closure $default): mixed
    {
        foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
            $name = $route->hasParameter($parameter->getName()) ? $parameter->getName() : Str::snake($parameter->getName());
            $value = $route->parameter($name);
            /** @var class-string<Model> $modelClass */
            $modelClass = Reflector::getParameterClassName($parameter);

            if (is_string($value) && $this->isFoundById(new $modelClass, $route->bindingFieldFor($name)) && ! $this->fitsAnId($value)) {
                throw (new ModelNotFoundException)->setModel($modelClass, [$value]);
            }
        }

        return $default();
    }

    private function isFoundById(Model $model, ?string $bindingField): bool
    {
        $field = $bindingField ?? $model->getRouteKeyName();

        return $field === $model->getKeyName() && in_array($model->getKeyType(), ['int', 'integer'], true);
    }

    /**
     * PHP's integers have the same range as PostgreSQL's bigint.
     */
    private function fitsAnId(string $value): bool
    {
        return ctype_digit($value) && filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
}
