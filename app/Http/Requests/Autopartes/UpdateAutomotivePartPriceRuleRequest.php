<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartPriceRule;
use Illuminate\Validation\Validator;

class UpdateAutomotivePartPriceRuleRequest extends StoreAutomotivePartPriceRuleRequest
{
    public function after(): array
    {
        return [function (Validator $validator) {
            $rule = $this->route('rule');
            if ($rule instanceof AutomotivePartPriceRule && $rule->status !== 'draft') {
                $validator->errors()->add('rule', 'Una regla activa aprobada es inmutable; cree una nueva versión.');
            }
        }];
    }
}
