<?php

/**
 * Laravel Controller for handling request to the translator admin interface
 */
namespace Hokan22\LaravelTranslator\Controllers;

use Illuminate\Routing\Controller;
use Hokan22\LaravelTranslator\Models\TranslationIdentifier;
use Hokan22\LaravelTranslator\TranslatorFacade;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Class TranslatorAdminController
 *
 * @package  Hokan22\LaravelTranslator\Controllers
 * @author   Alexander Viertel <alexander@aviertel.de>
 * @license  http://opensource.org/licenses/MIT MIT
 */
class TranslatorAdminController extends Controller  {

    /**
     * Return an overview of translations
     * @param Request $request
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request) {
        $search = $request->get('search', '');
        $locale = $request->get('locale', '');

        $query = TranslationIdentifier::with('translations');

        if ($locale != '') {
            $query = $query->whereDoesntHave('translations', function($query) use ($locale) {
                    $query->where('translations.locale', 'like', $locale);
                }
            );
        }

        if ($search != '') {
            $query = $query->where(function($query) use ($search) {
                $query ->where('identifier', 'LIKE', '%' . $search . '%')
                    ->orWhere('parameters', 'LIKE', '%' . $search . '%')
                    ->orWhere('group', 'LIKE', '%' . $search . '%')
                    ->orWhere('page_name', 'LIKE', '%' . $search . '%')
                    ->orWhere('description', 'LIKE', '%' . $search . '%');
            });
        }

        $trans_identifier = $query->orderBy('id')->paginate(20)->appends($request->except('page'));

        $available_locales = TranslatorFacade::getConfigValue('available_locales');

        return view('translator::index',
            [
                'identifier'        =>  $trans_identifier,
                'available_locales' =>  $available_locales,
                'page'              =>  $request->get('page'),
                'query_locale'      =>  $request->get('locale'),
                'search'            =>  $request->get('search'),
            ]
        );
    }

    /**
     * Return the edit view for a translation with $id
     *
     * @param Request $request
     * @param integer $id ID of the translation Identifier to edit
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit(Request $request, $id) {
        $identifier = TranslationIdentifier::findOrFail($id);

        $available_locales = TranslatorFacade::getConfigValue('available_locales');
        
        return view('translator::edit',
            [
                'identifier'        =>  $identifier,
                'available_locales' =>  $available_locales,
                'page'              =>  $request->get('page'),
                'locale'            =>  $request->get('locale'),
                'search'            =>  $request->get('search'),
            ]
        );
    }

    /**
     * Update
     *
     * @param integer $id ID of the identifier to edit
     * @param Request $request Object with the values of the identifier
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function postEdit($id, Request $request) {
        TranslationIdentifier::findOrFail($id);

        foreach ($request->all() as $key => $value) {

            if ($value === null || in_array($key, ['_token', 'page', 'locale', 'search'])) {
                continue;
            }

            $value = str_replace("\r", "", $value);
            $value = str_replace("\n", "<br />", $value);

            $timestamp = Carbon::now();

            $value = DB::connection()->getPdo()->quote($value);

            // Eloquent doesn't support composite keys, therefore a raw query is used
            // This query will create the translation or update the translation if it already exists in the database
            DB::insert("INSERT INTO `translations` (`translation_identifier_id`, `locale`, `translation`, `updated_at`, `created_at`)
                            VALUES ($id, '$key', $value, '$timestamp', '$timestamp') 
                            ON DUPLICATE KEY 
                            UPDATE `translation` = $value");

            DB::update("UPDATE `translation_identifiers` SET `updated_at` = '$timestamp' WHERE `id` LIKE $id");
        }

        return $this->edit($request, $id);
    }

    /**
     * Post edit of multiple Identifiers from the index view
     *
     * @param Request $request Request with multiple values of identifiers to update
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function postIdentifier(Request $request) {
        /** @var TranslationIdentifier $translation_identifiers */
        $translation_identifiers = TranslationIdentifier::all()->whereIn('id', array_keys($request->all()));

        foreach ($request->all() as $id => $identifier) {

            if (!is_array($identifier)) {
                continue;
            };

            $translation_identifier = $translation_identifiers->find($id);

            $translation_identifier->description    = isset($identifier['description']) ? $identifier['description'] : null;

            $translation_identifier->save();
        }
        return $this->index($request);
    }

    /**
     * Test view with some test translations
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function test() {
        return view('translator::test');
    }

    /**
     * @param $state string 'enabled|disabled'
     * @return \Illuminate\Http\RedirectResponse
     */
    public function changeLiveMode ($state) {
        if ($state == 'enable') {
            session(['translation_live_mode' => true]);
        } else {
            session(['translation_live_mode' => false]);
        }

        return redirect()->back();
    }
}