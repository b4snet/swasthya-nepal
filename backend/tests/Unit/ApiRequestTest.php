<?php

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\ValidationException;

/**
 * Strict validation foundation (API_CONTRACTS.md §6, MASTER_RULES.md §12.5):
 * unknown fields are rejected with a 422, never silently ignored.
 */
class RegistrationProbeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'fullName' => ['required', 'string', 'max:100'],
        ];
    }
}

function runRequest(array $payload): array
{
    // Laravel 12's failedValidation always resolves the redirect URL, so the
    // request needs a redirector; with Accept: application/json the failure
    // still throws the API's ValidationException rather than redirecting.
    $request = RegistrationProbeRequest::create('/api/v1/probe', 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request->validated();
}

it('accepts exactly the documented fields', function () {
    $validated = runRequest([
        'email' => 'nurse@demo.example',
        'fullName' => 'Poudel Nurse',
    ]);

    expect($validated)->toMatchArray([
        'email' => 'nurse@demo.example',
        'fullName' => 'Poudel Nurse',
    ]);
});

it('rejects unknown fields instead of ignoring them', function () {
    runRequest([
        'email' => 'nurse@demo.example',
        'fullName' => 'Poudel Nurse',
        'tenantId' => 'someone-elses-tenant', // forged/typo — must be rejected
    ]);
})->throws(ValidationException::class, 'tenantId');

it('still rejects invalid values for known fields', function () {
    runRequest([
        'email' => 'not-an-email',
        'fullName' => '',
    ]);
})->throws(ValidationException::class, 'email');
