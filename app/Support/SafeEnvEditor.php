<?php

namespace App\Support;

use App\Rules\EnvSafe;
use Dipokhalder\EnvEditor\EnvEditor;
use InvalidArgumentException;

/**
 * The env editor every caller gets (bound in AppServiceProvider).
 *
 * The form requests already refuse these values with a readable message; this
 * is the backstop for any path that writes .env without one. It refuses the
 * whole write before touching the file, so .env is never left half-updated or
 * unparseable. See App\Rules\EnvSafe for what each character would do.
 */
class SafeEnvEditor extends EnvEditor
{
    public function addData($data = array())
    {
        foreach ((array) $data as $key => $value) {
            if (!EnvSafe::isSafe($value === null ? null : (string) $value)) {
                throw new InvalidArgumentException("Refusing to write {$key} to .env: it contains quotes, backslashes, line breaks or \${…}.");
            }
        }

        return parent::addData($data);
    }
}
