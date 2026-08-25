<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Services\Platform\FeatureFlags;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Turning something on for some of the shop rather than all of it.
 *
 * The only lever this platform had was publishing or unpublishing a whole addon module, so every
 * change went live for every seller and every shopper at the same moment and the only way back was
 * a deployment. This is the smaller lever: a master switch, a percentage, and a pilot list of shops
 * somebody is watching.
 *
 * Deliberately not a free-text settings page. A flag is read by code, so the key is validated to the
 * same shape a policy key has — a flag whose key does not match what the code asks for is a switch
 * that silently does nothing, which is the worst possible thing for a control an operator reaches
 * for during an incident.
 */
class FeatureFlagController extends BaseController
{
    public function __construct(private readonly FeatureFlags $flags)
    {
    }

    public function index(?Request $request = null, ?string $type = null): View
    {
        return view('admin-views.settings.feature-flags', [
            'flags' => $this->flags->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $result = $this->flags->save((string) $request->get('key', ''), [
            'description' => $request->get('description'),
            'enabled' => $request->boolean('enabled'),
            'rollout_percent' => $request->get('rollout_percent'),
            'seller_ids' => $request->get('seller_ids'),
        ]);

        if (!$result['ok']) {
            ToastMagic::error(translate($result['error']));

            return back();
        }

        ToastMagic::success(translate('the_flag_was_saved'));

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Deleting returns everyone to the old behaviour at once, which is indistinguishable from
        // the new code having been reverted — so it is a POST, and it is audited by the service.
        $this->flags->delete((string) $request->get('key', ''))
            ? ToastMagic::success(translate('the_flag_was_removed'))
            : ToastMagic::error(translate('that_flag_does_not_exist'));

        return back();
    }
}
