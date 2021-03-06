<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

use App\Article;
use App\Collection;
use App\Item;
use App\Slide;
use App\Order;

Route::get('leto', function () {

    return redirect('kolekcia/25');
});

Route::get('/', function () {

    $slides = Slide::published()->orderBy('id', 'desc')->get();
    $articles = Article::promoted()->published()->orderBy('published_date', 'desc')->get();

    return view('intro', [
        'slides' => $slides,
        'articles' => $articles,
    ]);
});

Route::get('slideClicked', function () {
    $slide = Slide::find(Input::get('id'));
    if ($slide) {
        $slide->click_count += 1;
        $slide->save();

        return Response::json(['status' => 'success']);
    }
});

Route::get('objednavka', function () {

    $items = Item::find(Session::get('cart', array()));

    return view('objednavka', array('items' => $items));
});

Route::post('objednavka', function () {

    $input = Input::all();

    $rules = Order::$rules;
    $v = Validator::make($input, $rules);
    $v->sometimes('purpose', 'required|max:1500', function ($input) {

        return $input->format == 'digitálna reprodukcia';
    });
    $v->sometimes('delivery_point', 'required', function ($input) {

        return $input->format != 'digitálna reprodukcia';
    });

    if ($v->passes()) {
        $order = new Order();
        $order->name = Input::get('name');
        $order->address = Input::get('address');
        $order->email = Input::get('email');
        $order->phone = Input::get('phone');
        $order->format = Input::get('format');
        $order->purpose_kind = Input::get('purpose_kind');
        $order->purpose = Input::get('purpose');
        $order->delivery_point = Input::get('delivery_point', null);
        $order->note = Input::get('note');
        $order->save();

        $item_ids = explode(', ', Input::get('pids'));

        foreach ($item_ids as $item_id) {
            $order->items()->attach($item_id);
        }

        //poslat objednavku do Jiry
        $client = new GuzzleHttp\Client();
        $res = $client->post('http://jira.sng.sk/rest/cedvu/latest/order/create', [
            'auth' => [Config::get('app.jira_auth.user'), Config::get('app.jira_auth.pass')],
            'form_params' => [
                'pids' => Input::get('pids'),
                'organization' => $order->name,
                'contactPerson' => $order->name,
                'email' => $order->email,
                'kindOfPurpose' => $order->purpose_kind,
                'purpose' => $order->purpose."\n".$order->format."\n".$order->delivery_point."\n".$order->note,
                'medium' => 'Iné',
                'address' => $order->address,
                'phone' => $order->phone,
                'ico' => '',
                'dic' => '',
                'numOfCopies' => '1',
            ],
        ]);
        if ($res->getStatusCode() == 200) {
            Session::forget('cart');

            return redirect('dakujeme');
        } else {
            Session::flash('message', 'Nastal problém pri uložení vašej objednávky. Prosím kontaktujte lab@sng.sk. ');

            return Redirect::back()->withInput();
        }
    }

    return Redirect::back()->withInput()->withErrors($v);

});

Route::get('dakujeme', function () {

    return view('dakujeme');
});

Route::get('dielo/{id}/zoom', function ($id) {

    $item = Item::find($id);

    if (empty($item->iipimg_url)) {
        App::abort(404);
    }

    $related_items = (!empty($item->related_work)) ? Item::where('related_work', '=', $item->related_work)->where('author', '=', $item->author)->whereNotNull('iipimg_url')->orderBy('related_work_order')->lists('iipimg_url')->toArray() : [];

    return view('zoom', array('item' => $item, 'related_items' => $related_items));
});

Route::get('ukaz_skicare', 'SkicareController@index');
Route::get('skicare', 'SkicareController@getList');
Route::get('dielo/{id}/skicar', 'SkicareController@getZoom');

Route::get('dielo/{id}/objednat', function ($id) {

    $item = Item::find($id);

    if (empty($item) || !$item->isForReproduction()) {
        App::abort(404);
    }

    if (!in_array($item->id, Session::get('cart', array()))) {
        Session::push('cart', $item->id);
    }

    Session::flash('message', 'Dielo <b>'.implode(', ', $item->authors)." – $item->title</b> (".$item->getDatingFormated().') bolo pridané do košíka.');

    return redirect($item->getUrl());

});

Route::get('dielo/{id}/odstranit', function ($id) {

    $item = Item::find($id);

    if (empty($item)) {
        App::abort(404);
    }
    Session::put('cart', array_diff(Session::get('cart'), [$item->id]));
    Session::flash('message', 'Dielo <b>'.implode(', ', $item->authors)." – $item->title</b> (".$item->getDatingFormated().') bolo odstránené z košíka.');

    return Redirect::back();

});

Route::get('dielo/{id}/stiahnut', ['middleware' => 'throttle:5,1', function ($id) {

    $item = Item::find($id);

    if (empty($item) || !$item->isFreeDownload()) {
    	App::abort(404);
    }
    $item->timestamps = false;
    $item->download_count += 1;
    $item->save();
    $item->download();

    // return Response::download($pathToFile);
}]);

Route::get('dielo/{id}', function ($id) {

    $item = Item::find($id);
    if (empty($item)) {
        App::abort(404);
    }
    $item->timestamps = false;
    $item->view_count += 1;
    $item->save();
    $previous = $next = false;

    // $more_items = Item::moreLikeThis(['author','title.stemmed','description.stemmed', 'tag', 'place'],[$item->id])->limit(20);
    $more_items = $item->moreLikeThis(30);

    if (Input::has('collection')) {
        $collection = Collection::find((int) Input::get('collection'));
        if (!empty($collection)) {
            $items = $collection->items->lists('id')->all();
            $previousId = getPrevVal($items, $id);
            if ($previousId) {
                $previous = Item::find($previousId)->getUrl(['collection' => $collection->id]);
            }
            $nextId = getNextVal($items, $id);
            if ($nextId) {
                $next = Item::find($nextId)->getUrl(['collection' => $collection->id]);
            }
        }
    }

    return view('dielo', array('item' => $item, 'more_items' => $more_items, 'previous' => $previous, 'next' => $next));
});

Route::controller('katalog', 'CatalogController');
// Route::match(array('GET', 'POST'), 'katalog', 'CatalogController@index');
// Route::match(array('GET', 'POST'), 'katalog/suggestions', 'CatalogController@getSuggestions');

Route::match(array('GET', 'POST'), 'autori', 'AuthorController@getIndex');
Route::match(array('GET', 'POST'), 'autori/suggestions', 'AuthorController@getSuggestions');
Route::get('autor/{id}', 'AuthorController@getDetail');

Route::match(array('GET', 'POST'), 'clanky', 'ClanokController@getIndex');
Route::match(array('GET', 'POST'), 'clanky/suggestions', 'ClanokController@getSuggestions');
Route::get('clanok/{slug}', 'ClanokController@getDetail');

Route::match(array('GET', 'POST'), 'kolekcie', 'KolekciaController@getIndex');
Route::match(array('GET', 'POST'), 'kolekcie/suggestions', 'KolekciaController@getSuggestions');
Route::get('kolekcia/{slug}', 'KolekciaController@getDetail');

Route::get('informacie', function () {

    // $items = Item::forReproduction()->hasImage()->hasZoom()->limit(20)->orderByRaw("RAND()")->get();
    $items = Item::random(20, ['gallery' => 'Slovenská národná galéria, SNG']);

    return view('informacie', ['items' => $items]);
});

Route::group(array('middleware' => 'guest'), function () {
    Route::get('login', 'AuthController@getLogin');
    Route::post('login', 'AuthController@postLogin');
});

Route::group(['middleware' => ['auth', 'role:admin|editor']], function () {

    Route::get('admin', 'AdminController@index');
    Route::get('logout', 'AuthController@logout');
    Route::get('collection/{collection_id}/detach/{item_id}', 'CollectionController@detach');
    Route::post('collection/fill', 'CollectionController@fill');
    Route::post('collection/sort', 'CollectionController@sort');
    Route::resource('collection', 'CollectionController');
    Route::resource('user', 'UserController');
    Route::match(['get', 'post'], 'uploader', 'FileuploaderController@upload');
});

Route::group(['middleware' => ['auth', 'role:admin']], function () {
    Route::resource('article', 'ArticleController');
    Route::get('harvests/launch/{id}', 'SpiceHarvesterController@launch');
    Route::get('harvests/orphaned/{id}', 'SpiceHarvesterController@orphaned');
    Route::get('harvests/{record_id}/refreshRecord/', 'SpiceHarvesterController@refreshRecord');
    Route::resource('harvests', 'SpiceHarvesterController');
    Route::get('item/backup', 'ItemController@backup');
    Route::get('item/geodata', 'ItemController@geodata');
    Route::post('item/destroySelected', 'ItemController@destroySelected');
    Route::post('item/refreshSelected', 'ItemController@refreshSelected');
    Route::get('item/search', 'ItemController@search');
    Route::get('item/reindex', 'ItemController@reindex');
    Route::resource('item', 'ItemController');
    Route::get('authority/destroyLink/{link_id}', 'AuthorityController@destroyLink');
    Route::get('authority/search', 'AuthorityController@search');
    Route::get('authority/reindex', 'AuthorityController@reindex');
    Route::post('authority/destroySelected', 'AuthorityController@destroySelected');
    Route::resource('authority', 'AuthorityController');
    Route::resource('sketchbook', 'SketchbookController');
    Route::resource('slide', 'SlideController');
    Route::get('logs', '\Rap2hpoutre\LaravelLogViewer\LogViewerController@index');
});

// App::error(function (Exception $exception) {

//     if (Config::get('app.debug')) {
//         return;
//     }
//     Log::error($exception);

//     return Response::view('errors.fatal', array(), 500);
// });

// App::missing(function ($exception) {

//     if (Request::is('cedvuweb/image/*')) {
//         $id = Input::get('id');
//         if (!empty($id)) {
//             return redirect(Item::getImagePathForId($id), 301);
//         }
//     } elseif (Request::is('web/guest/*') || Request::is('web/ogd/*') || Request::is('web/gmb/*') || Request::is('web/gnz/*')) {
//         $filter_lookup = [
//             'author' => 'au',
//             'work_type' => 'wt',
//             'topic' => 'to',
//         ];
//         $work_type_lookup = [
//             'photo' => 'fotografia',
//             'graphic' => 'grafika',
//             'drawing' => 'kresba',
//             'painting' => 'maliarstvo',
//             'sculpture' => 'sochárstvo',
//             'graphic_design' => 'úžitkové umenie',
//             'aplplied_arts' => 'úžitkové umenie',
//             'ine_media' => 'iné médiá',
//             'umelecke_remeslo' => 'umelecké remeslo',
//         ];
//         $uri = Request::path();
//         $params = http_build_query(Input::all());
//         $uri = (!$params) ? $uri : $uri.'/'.$params;
//         $uri = str_replace('results?', 'results/', $uri);
//         $parts = explode('/', $uri);
//         $action = $parts[2];
//         switch ($action) {
//             case 'home':
//                 return redirect('/', 301);
//                 break;

//             case 'about':
//             case 'contact':
//             case 'help':
//                 return redirect('informacie', 301);
//                 break;

//             case 'detail':
//                 $id = $parts[6];
//                 $item = Item::find($id);
//                 if ($item) {
//                     return redirect($item->getUrl(), 301);
//                 }
//                 break;

//             case 'search':
//                 $query = array_pop($parts);
//                 $query = urldecode($query);
//                 parse_str($query, $output);
//                 // $query = preg_replace("/(\w+)[=]/", " ", $query); // vymaze slova konciace "=" alebo ":" -> napr "au:"
//                 $query = (isset($output['query'])) ? $output['query'] : '';
//                 if (preg_match_all('/\s*([^:]+):(.*)/', $query, $matches)) {
//                     $apply_filters = array();
//                     $filters = array_combine($matches[1], $matches[2]);
//                     foreach ($filters as $filter => $value) {
//                         if (in_array($filter, $filter_lookup)) {
//                             $filter = array_search($filter, $filter_lookup);
//                             switch ($filter) {
//                                 case 'work_type':
//                                     $parts = explode(', ', $value);
//                                     $value = reset($parts);
//                                     $value = str_to_alphanumeric($value);
//                                     $apply_filters[$filter] = $value;
//                                     break;
//                                 case 'author':
//                                     $replace_pairs = ['"' => '', '\"' => '', '“' => ''];
//                                     $value = strtr($value, $replace_pairs);
//                                     $parts = explode(' ', $value);
//                                     if (count($parts) > 1) {
//                                         $last_name = array_pop($parts);
//                                         $value = $last_name.', '.implode(' ', $parts);
//                                         $apply_filters[$filter] = $value;
//                                     }
//                                     break;
//                                 default:
//                                     $replace_pairs = ['"' => '', '\"' => '', '“' => ''];
//                                     $value = strtr($value, $replace_pairs);
//                                     $apply_filters[$filter] = $value;
//                                     break;
//                             }
//                         }
//                     }
//                     if (!empty($apply_filters)) {
//                         return redirect('katalog?'.http_build_query($apply_filters), 301);
//                     }
//                     $query = implode(' ', $filters);
//                 }
//                 $query = $value = str_to_alphanumeric($query, ' ');

//                 return redirect('katalog?search='.urlencode($query), 301);
//                 break;

//             case array_key_exists($action, $work_type_lookup):
//                 $work_type = $work_type_lookup[$action];

//                 return redirect(URL::to('katalog?work_type='.$work_type), 301);
//                 break;

//             default:
//                 # code...
//                 break;
//         }
//     }

//     // $item = Item::forReproduction()->hasImage()->hasZoom()->limit(20)->orderByRaw("RAND()")->first();
//     $item = Item::random()->first();

//     return Response::view('errors.missing', ['item' => $item], 404);
// });
