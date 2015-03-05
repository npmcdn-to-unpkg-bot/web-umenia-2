<?php

class SpiceHarvesterController extends \BaseController {

    const OAI_DATE_FORMAT = 'Y-m-d';

    const METADATA_SCHEMA = 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd';
    const METADATA_PREFIX = 'oai_dc';

    const OAI_DC_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
    const DUBLIN_CORE_NAMESPACE_ELEMTS = 'http://purl.org/dc/elements/1.1/';
    const DUBLIN_CORE_NAMESPACE_TERMS = 'http://purl.org/dc/terms/';


	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
	{
		$harvests = SpiceHarvesterHarvest::orderBy('created_at', 'DESC')->paginate(10);
        return View::make('harvests.index')->with('harvests', $harvests);
	}

	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
		return View::make('harvests.form');
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store()
	{
		$input = Input::all();

		$rules = SpiceHarvesterHarvest::$rules;
		$v = Validator::make($input, $rules);

		if ($v->passes()) {
			
			$harvest = new SpiceHarvesterHarvest;
			$harvest->base_url = Input::get('base_url');
			$harvest->type = Input::get('type');
			$harvest->metadata_prefix = Input::get('metadata_prefix');
			$harvest->set_spec = Input::get('set_spec');
			$harvest->set_name = Input::get('set_name');
			$harvest->set_description = Input::get('set_description');
			$collection = Collection::find(Input::get('collection_id'));
			if ($collection) $harvest->collection()->associate($collection);			
			if (Input::has('username') && Input::has('password')) {
				$harvest->username = Input::get('username');
				$harvest->password = Input::get('password');				
			}
			$harvest->save();

			return Redirect::route('harvests.index');
		}

		return Redirect::back()->withInput()->withErrors($v);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($id)
	{
		$harvest = SpiceHarvesterHarvest::find($id);
		$harvest->load('collection');
        return View::make('harvests.show')->with('harvest', $harvest);
	}

	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($id)
	{
		$harvest = SpiceHarvesterHarvest::find($id);

		if(is_null($harvest))
		{
			return Redirect::route('harvest.index');
		}

        return View::make('harvests.form')->with('harvest', $harvest);
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($id)
	{
		$v = Validator::make(Input::all(), SpiceHarvesterHarvest::$rules);

		if($v->passes())
		{
			$input = array_except(Input::all(), array('_method'));

			$harvest = SpiceHarvesterHarvest::find($id);
			$harvest->base_url = Input::get('base_url');
			$harvest->type = Input::get('type');
			if (Input::has('username') && Input::has('password')) {
				$harvest->username = Input::get('username');
				$harvest->password = Input::get('password');				
			}
			$harvest->metadata_prefix = Input::get('metadata_prefix');
			$harvest->set_spec = Input::get('set_spec');
			$harvest->set_name = Input::get('set_name');
			$harvest->set_description = Input::get('set_description');
			// $collection = Collection::find(Input::get('collection_id'));
			// if ($collection->count()) $harvest->collection()->associate($collection);
			$harvest->collection_id = Input::get('collection_id');
			$harvest->save();

			Session::flash('message', 'Harvest <code>'.$harvest->set_spec.'</code> bol upravený');
			return Redirect::route('harvests.index');
		}

		return Redirect::back()->withErrors($v);
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id)
	{
		$harvest = SpiceHarvesterHarvest::find($id);
		$set_spec = $harvest->set_spec;
		foreach ($harvest->records as $i => $record) {
			Item::destroy($record->item_id);
			$record->delete();
		}		
		$harvest->delete();
		return Redirect::route('harvests.index')->with('message', 'Harvest <code>'.$set_spec.'</code>  bol zmazaný');;
	}

	public function orphaned($id)
	{
		$processed_items = 0;
	    $removed_items = 0;
	    $timeStart = microtime(true);
        $start_from = null;

		$harvest = SpiceHarvesterHarvest::find($id);
		$client = new \Phpoaipmh\Client($harvest->base_url);
	    $myEndpoint = new \Phpoaipmh\Endpoint($client);

	    $items_to_remove = array();

	    foreach ($harvest->records as $i => $record) {
	    	$processed_items++;
	    	$remove_id = true;
	    	$rec = $myEndpoint->getRecord($record->item_id, $harvest->metadata_prefix);
	    	if (!empty($rec)) {	    		
		    	$setSpecs = (array) $rec->GetRecord->record->header->setSpec;
		    	// if ($setSpec==$harvest->set_spec) {
		    	if (in_array($harvest->set_spec, $setSpecs)) {
		    		$remove_id = false;		
		    	} 
	    	}
	    	if ($remove_id) {
	    		$items_to_remove[] = $record->item_id;
	    	}
	    }
	    
		$collections = Collection::lists('name', 'id');
		if (count($items_to_remove)) {
			$items = Item::whereIn('id', $items_to_remove)->paginate('50');
		} else {
			$items = Item::where('id','=',0);
		}
		Session::flash('message', 'Našlo sa ' .count($items_to_remove). ' záznamov, ktoré sa už nenachádzajú v OAI sete ' . $harvest->set_name . ':');		
        return View::make('items.index', array('items' => $items, 'collections' => $collections));		

	}

	/**
	 * Launch the harvest process
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function launch($id)
	{
		$reindex = Input::get('reindex', false);
		$processed_items = 0;
	    $new_items = 0;
	    $updated_items = 0;
	    $skipped_items = 0;
	    $timeStart = microtime(true);

		$harvest = SpiceHarvesterHarvest::find($id);

        $start_from = null;

		if ($harvest->status == SpiceHarvesterHarvest::STATUS_COMPLETED && !$reindex) {
            $start_from = new DateTime($harvest->initiated);
            // $start_from->sub(new DateInterval('P1D'));
        } 

		$harvest->status = $harvest::STATUS_QUEUED;
		$harvest->initiated = date('Y-m-d H:i:s');
		$harvest->save();


		$harvest->status = $harvest::STATUS_IN_PROGRESS;
		$harvest->status_messages = '';
		$harvest->save();

		//--- nazacat samostatnu metodu
		$guzzleAdapter = null;
		if ($harvest->username && $harvest->password) {
			$gclient = new GuzzleHttp\Client(['defaults' =>  ['auth' =>  [$harvest->username, $harvest->password]]]);
			$guzzleAdapter = new \Phpoaipmh\HttpAdapter\GuzzleAdapter($gclient);			
		}
		$client = new \Phpoaipmh\Client($harvest->base_url, $guzzleAdapter);
	    $myEndpoint = new \Phpoaipmh\Endpoint($client);

    	$recs = $myEndpoint->listRecords($harvest->metadata_prefix, $start_from, null, $harvest->set_spec);
	    $dt = new \DateTime;

	    //zmaz potom
	    // $rec = $myEndpoint->getRecord(1330, $harvest->metadata_prefix)->GetRecord->record; //med=6478
	    // $this->insertRecord($id, $rec, $harvest->type);
	    //zmaz potom
	    // dd('staci');
	    try {
	    foreach($recs as $rec) {
	    	$processed_items++;

	    	if (!$this->isDeletedRecord($rec)) { //ak je v sete oznaceny ako zmazany

	    		//ak bol zmazany v tu v databaze, ale nachadza sa v OAI sete
	    		$is_deleted_record = SpiceHarvesterRecord::onlyTrashed()->where('identifier', '=', $rec->header->identifier)->where('type', '=', $harvest->type)->count();
	    		if ($is_deleted_record > 0) {
	    			$skipped_items++;
	    		//inak insert alebo update
	    		} else {
					$existingRecord = SpiceHarvesterRecord::where('identifier', '=', $rec->header->identifier)->where('type', '=', $harvest->type)->first();

			        if ($existingRecord) {
			            // ak sa zmenil datestamp, update item - inak ignorovat
			            // if( $existingRecord->datestamp != $rec->header->datestamp) {
			                $this->updateRecord($existingRecord, $rec, $harvest->type);
			                $updated_items++;
			            // }
			        } else {
			            $this->insertRecord($id, $rec, $harvest->type);
			            $new_items++;
			        }
			    }

   	    	}
	    }
        } catch (\Phpoaipmh\Exception\MalformedResponseException $e) {
            // $harvest->status = SpiceHarvesterHarvest::STATUS_ERROR; 
            // tuto chybu vrati, ak ziadne records niesu - cize harvest moze pokracovat dalej
            $harvest->status_messages = $e->getMessage() . "\n";
        }


		if ($harvest->collection) {
			$collection = $harvest->collection;
			foreach ($harvest->records as $i => $record) {
				if (!$collection->items->contains($record->item_id)) {
				    $collection->items()->attach($record->item_id);
				}
			}
		}

	    $totalTime = round((microtime(true)-$timeStart));
	    $message = 'Spracovaných bolo ' . $processed_items . ' záznamov. Z toho pribudlo ' . $new_items . ' nových záznamov,  ' . $updated_items . ' bolo upravených a ' . $skipped_items . ' bolo preskočených. Trvalo to ' . $totalTime . 's';

	    $harvest->status = SpiceHarvesterHarvest::STATUS_COMPLETED;
	    $harvest->status_messages .= $message;
		$harvest->completed = date('Y-m-d H:i:s');
	    $harvest->save();


	    Session::flash('message', $message);
	    return Redirect::route('harvests.index');
	}


    /**
     * Return whether the record is deleted
     * 
     * @param SimpleXMLIterator The record object
     * @return bool
     */
    private function isDeletedRecord($rec)
    {
        if (isset($rec->header->attributes()->status) 
            && $rec->header->attributes()->status == 'deleted') {
	        return true;
        }
        return false;
    }

    /**
     * Convenience method for inserting an item and its files.
     * 
     * Method used by map writers that encapsulates item and file insertion. 
     * Items are inserted first, then files are inserted individually. This is 
     * done so Item and File objects can be released from memory, avoiding 
     * memory allocation issues.
     * 
     * @param int $harvest_id
     * @param SimpleXMLElement $rec OAI PMH record
     * @return true
     */
    private function insertRecord($harvest_id, $rec, $type) {

    	switch ($type) {
    		case 'item':
		    	$attributes = $this->mapItemAttributes($rec);
			    $item = Item::create($attributes);
			    $item->authorities()->sync($attributes['authority_ids']);
    			break;
    		case 'author':
		    	// $nationality = Nationality::firstOrNew(['id' => ])
		    	$attributes = $this->mapAuthorAttributes($rec);
			    $author = Authority::create($attributes);
			    if (!empty($attributes['nationalities'])) {
			    	$nationality_ids = array();
				    foreach ($attributes['nationalities'] as $key => $nationality) {
				    	$nationality = Nationality::firstOrCreate($nationality);
				    	$nationality_ids[] = $nationality['id'];
				    }
				    $nationality = $author->nationalities()->sync($nationality_ids);
				}
			    if (!empty($attributes['roles'])) {
				    foreach ($attributes['roles'] as $key => $role) {
				    	$role['authority_id'] = $author->id;
				    	$role = AuthorityRole::firstOrCreate($role);
				    }
				}
			    if (!empty($attributes['names'])) {
				    foreach ($attributes['names'] as $key => $name) {
				    	$name['authority_id'] = $author->id;
				    	$name = AuthorityName::firstOrCreate($name);
				    }
				}
			    if (!empty($attributes['events'])) {
				    foreach ($attributes['events'] as $key => $event) {
				    	$event['authority_id'] = $author->id;
				    	$event = AuthorityEvent::firstOrCreate($event);
				    }
				}
			    if (!empty($attributes['relationships'])) {
				    foreach ($attributes['relationships'] as $key => $relationship) {
				    	$relationship['authority_id'] = $author->id;
				    	$relationship = AuthorityRelationship::firstOrCreate($relationship);
				    }
				}
			    if (!empty($attributes['links'])) {
				    foreach ($attributes['links'] as $key => $url) {
				    	$link = new Link();
				    	$link->url = $url;
				    	$url_parts = parse_url($url);
				    	$link->label = $url_parts['host'];
				    	$author->links()->save($link);
				    }
				}
    			break;
    	}

		// Insert the record after the item is saved
	    $record = new SpiceHarvesterRecord();
	    $record->harvest_id = $harvest_id;
	    $record->type = $type;
	    $record->item_id = $attributes['id'];
	    $record->identifier = $rec->header->identifier;
	    $record->datestamp = $rec->header->datestamp;
	    $record->save();

      
        // Upload image given by url
        if (!empty($attributes['img_url'])) {
        	$this->downloadImage($item, $attributes['img_url']);
        }        

        return true;
    }
    
    /**
     * Method for updating an item
     * 
     * @param SpiceHarvesterRecord $existingRecord 
     * @param SimpleXML $rec OAI PMH record
     * @return true
     */
    private function updateRecord($existingRecord, $rec, $type) {

    	// Update the item
    	switch ($type) {
    		case 'item':
		    	$attributes = $this->mapItemAttributes($rec);
			    $item = Item::where('id', '=', $rec->header->identifier)->first();
			    $item->fill($attributes);
			    $item->authorities()->sync($attributes['authority_ids']);
			    $item->save();
    			break;
    		case 'author':
		    	$attributes = $this->mapAuthorAttributes($rec);
			    $author = Authority::where('id', '=', $rec->header->identifier)->first();
			    $author->fill($attributes);
			    if (!empty($attributes['links'])) {
				    foreach ($attributes['links'] as $key => $url) {
				    	// dd($url);
				    	$link = new Link();
				    	$link->url = $url;
				    	$url_parts = parse_url($url);
				    	$link->label = $url_parts['host'];
				    	$author->links()->save($link);
				    }
				}
			    $author->save();
    			break;
    	}

        // Upload image given by url
        if (!empty($attributes['img_url'])) {
        	$this->downloadImage($item, $attributes['img_url']);
        }        
        
        // Update the datestamp stored in the database for this record.
	    $existingRecord->datestamp = $rec->header->datestamp;
	    // $existingRecord->updated_at =  date('Y-m-d H:i:s'); //toto by sa malo diat automaticky
	    $existingRecord->save();


        return true;
    }

    /**
     * Map attributes from OAI to author schema
     */
    private function mapAuthorAttributes($rec)
    {
		$vendorDir = base_path() . '/vendor';
	    // include($vendorDir . '/imsop/simplexml_debug/src/simplexml_dump.php');
	    // include($vendorDir . '/imsop/simplexml_debug/src/simplexml_tree.php');

    	$attributes = array();
// simplexml_tree($rec);  dd();
		$rec->registerXPathNamespace('cedvu', 'http://www.webumenia.sk#');
		$rec->registerXPathNamespace('ulan', 'http://e-culture.multimedian.nl/ns/getty/ulan');
		$rec->registerXPathNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns');
		$rec->registerXPathNamespace('vp', 'http://e-culture.multimedian.nl/ns/getty/vp');
		$metadata = $rec->metadata->children('cedvu', true)->Vocabulary->children('vp', true)->Subject;

		$attributes['id'] = (int)$this->parseId((string)$metadata->attributes('rdf', true)->about);
		$attributes['type'] = (string)$metadata->Record_Type;
		$attributes['type_organization'] = (string)$metadata->Record_Type_Organization;
		$attributes['name'] = (string)$metadata->attributes('vp', true)->labelPreferred;
		$attributes['sex'] = (string)$metadata->Biographies->Preferred_Biography->Sex;
		$biography = $this->parseBiography((string)$metadata->Biographies->Preferred_Biography->Biography_Text);
		if (strpos($biography, 'http')!== false) {			
			preg_match_all('!https?://\S+!', $biography, $matches);
			$attributes['links']= $matches[0];
			$biography = ''; // vymazat bio
		}
		$attributes['biography'] = $biography;
		if (!empty($metadata->Biographies->Preferred_Biography->Birth_Place))
			$attributes['birth_place'] = $this->trimAfter((string)$metadata->Biographies->Preferred_Biography->Birth_Place);
		if (!empty($metadata->Biographies->Preferred_Biography->Birth_Date))
			$attributes['birth_year'] = $this->parseYear($metadata->Biographies->Preferred_Biography->Birth_Date);
			$attributes['birth_date'] = (string)$metadata->Biographies->Preferred_Biography->Birth_Date;
		if (!empty($metadata->Biographies->Preferred_Biography->Death_Place))
			$attributes['death_place'] = $this->trimAfter((string)$metadata->Biographies->Preferred_Biography->Death_Place);
		if (!empty($metadata->Biographies->Preferred_Biography->Death_Date))
			$attributes['death_year'] = $this->parseYear($metadata->Biographies->Preferred_Biography->Death_Date);
			$attributes['death_date'] = (string)$metadata->Biographies->Preferred_Biography->Death_Date;
		$attributes['nationalities'] = array();
		foreach ($metadata->Nationalities->Preferred_Nationality as $key => $nationality) {
			$attributes['nationalities'][] = [
				'id' => (int)$this->parseId((string)$nationality->attributes('rdf', true)->resource),
				'code' => (string)$nationality->Nationality_Code,
				// 'prefered' => true,
			];
		}
		$attributes['roles'] = array();
		foreach ($metadata->Roles->Preferred_Role as $key => $role) {
			$attributes['roles'][] = [
				'role' => $this->trimAfter((string)$role->Role_ID),
				// 'prefered' => true,
			];
		}
		$attributes['names'] = array();
		foreach ($metadata->Terms->Preferred_Term as $key => $term) {
			$attributes['names'][] = [
				'name' => (string)$term->Term_Text,
				'prefered' => true,
			];
		}
		foreach ($metadata->Terms->{'Non-Preferred_Term'} as $key => $term) {
			$attributes['names'][] = [
				'name' => (string)$term->Term_Text,
				'prefered' => false,
			];
		}
		$attributes['events'] = array();
		foreach ($metadata->Events->{'Non-Preferred_Event'} as $key => $event) {
			$attributes['events'][] = [
				'id' => (int)$event->attributes('rdf', true)->resource,
				'event' => (string)$event->Event_ID,
				'place' => $this->trimAfter((string)$event->Place),
				'prefered' => false,
				'start_date' => (string)$event->Event_Date->Start_Date,
				'end_date' => (string)$event->Event_Date->End_Date,
			];
		}
		foreach ($metadata->Associative_Relationships->Associative_Relationship as $key => $relationship) {
			$name = (string)$relationship->{'Non-Preferred_Parent'};
			if (!empty($name)) {
				$attributes['relationships'][] = [
					'type' => (string)$relationship->Relationship_Type,
					'name' => (string)$relationship->{'Non-Preferred_Parent'},
					'realted_authority_id' => (int)$this->parseId((string)$relationship->{'Non-Preferred_Parent'}->attributes('rdf', true)->resource),
					// 'prefered' => true,
				];
			}
		}

	    return $attributes;
    }

    /**
     * Map attributes from OAI to item schema
     */
    private function mapItemAttributes($rec)
    {
    	$attributes = array();

		$dcElements = $rec->metadata
	                    ->children(self::OAI_DC_NAMESPACE)
	                    ->children(self::DUBLIN_CORE_NAMESPACE_ELEMTS);


		$dcTerms = $rec->metadata
	                    ->children(self::OAI_DC_NAMESPACE)
	                    ->children(self::DUBLIN_CORE_NAMESPACE_TERMS);

	    $type = (array)$dcElements->type;
	    $identifier = (array)$dcElements->identifier;

	    $topic=array(); // zaner - krajina s figuralnou kompoziciou / veduta
	    $subject=array(); // objekt - dome/les/

	    foreach ($dcElements->subject as $key => $value) {
	    	if ($this->starts_with_upper($value)) {
	    		$subject[] = mb_strtolower($value, "UTF-8");
	    	} else {
	    		$topic[] =$value;
	    	}
	    }

	    $attributes['id'] = (string)$rec->header->identifier;
	    $attributes['identifier'] = (!empty($identifier[2])) ? $identifier[2] : '';	    
	    $attributes['title'] = $dcElements->title;
	    $authors = array();
	    $authority_ids = array();
	    foreach ($dcElements->creator as $key => $creator) {
		    if (strpos($creator, 'urn:')!==false) {
		    	$authority_ids[] = (int)$this->parseId($creator);
		    } else {
		    	$authors[] = $creator;
		    }
	    }
	    $attributes['authority_ids'] = $authority_ids;
	    $attributes['author'] = $this->serialize($authors);
	    $attributes['work_type'] = $type[0];
	    $attributes['work_level'] = $type[1];
	    $attributes['topic'] = $this->serialize($topic);
	    $attributes['subject'] = $this->serialize($subject);
	    $attributes['place'] = $this->serialize($dcElements->{'subject.place'});
	    // $trans = array(", " => ";", "šírka" => "", "výška" => "", "()" => "");
	    $trans = array(", " => ";", "; " => ";", "()" => "");
	    $attributes['measurement'] = trim(strtr($dcTerms->extent, $trans));
	    $dating = explode('/', $dcTerms->created[0]);
	    $dating_text = (!empty($dcTerms->created[1])) ? end((explode(', ', $dcTerms->created[1]))) : $dcTerms->created[0];
	    $attributes['date_earliest'] = (!empty($dating[0])) ? $dating[0] : null;
	    $attributes['date_latest'] = (!empty($dating[1])) ? $dating[1] : $attributes['date_earliest'];
	    $attributes['dating'] = $dating_text;
	    $attributes['medium'] = $dcElements->{'format.medium'}; // http://stackoverflow.com/questions/6531380/php-simplexml-with-dot-character-in-element-in-xml
	    $attributes['technique'] = $this->serialize($dcElements->format);
	    $attributes['inscription'] = $this->serialize($dcElements->description);
	    $attributes['state_edition'] =  (!empty($type[2])) ? $type[2] : null;
	    $attributes['gallery'] = $dcTerms->provenance;
	    $attributes['img_url'] = (!empty($identifier[1]) && (strpos($identifier[1], 'http') === 0)) ? $identifier[1] : null; //ak nieje prazdne a zacina 'http'

	    // $attributes['iipimg_url'] = NULL; // by default
	    if (!empty($identifier[3]) && (strpos($identifier[3], 'http') === 0)) {
	    	$iip_resolver = $identifier[3];
	    	$str = @file_get_contents($iip_resolver);
	    	if ($str != FALSE) {

		    	$str = strip_tags($str, '<br>'); //zrusi vsetky html tagy okrem <br>
				$iip_urls = explode('<br>', $str); //rozdeli do pola podla <br>
				asort($iip_urls); // zoradi pole podla poradia - aby na zaciatku boli predne strany (1_2, 2_2 ... )
				$iip_url = reset($iip_urls); // vrati prvy obrazok z pola - docasne - kym neumoznime viacero obrazkov k dielu
		    	if (str_contains($iip_url, '.jp2')) { //fix: vracia blbosti. napr linky na obrazky na webumenia. ber to vazne len ak odkazuje na .jp2
			    	$iip_url = substr($iip_url, strpos( $iip_url, '?FIF=')+5);
			    	$iip_url = substr($iip_url, 0, strpos( $iip_url, '.jp2')+4);
			    	$attributes['iipimg_url'] = $iip_url;	    		
		    	}
		    }
	    }
	    
	    // pretypovat SimpleXMLElement na string
	    foreach ($attributes as $key=>$attribute) {
	    	if (is_object($attribute)) {
	    		$attributes[$key] = (string) $attribute;
	    	}
	    }

	    return $attributes;
    }

    private function serialize($attribute)
    {
    	return implode("; ", (array)$attribute);
    }

    private function starts_with_upper($str) {
	    $chr = mb_substr($str, 0, 1, "UTF-8");
	    return mb_strtolower($chr, "UTF-8") != $chr;
	}

	private function downloadImage($item, $img_url)
	{
    	$file = $img_url;
    	$data = file_get_contents($file);
    	$full = true;
     	if ($new_file = $item->getImagePath($full)) {
            file_put_contents($new_file, $data);
            return true;
     	}
     	return false;
	}

	private function parseId ( $string, $delimiter=':' )
	{
	    return substr($string, strrpos($string, $delimiter) + ( (strrpos($string, $delimiter)!== false) ? strlen($delimiter) : 0));
	}

	private function trimAfter ( $string, $delimiter='/' )
	{
	    $parts = explode($delimiter, $string);
	    return $parts[0];
	    // return substr($string, 0, strpos($string, $delimiter));
	}

	private function parseBiography ( $string )
	{
	    $bio = $this->parseId($string, '(ZNÁMY)');
	    return $bio;
	}

	private function parseDate ( $string )
	{
	    $result = null;
	    if (substr_count($string, '.')==2) {
	    	if ($date = DateTime::createFromFormat('d.m.Y|', $string))
	    		$result = $date->format('Y-m-d');
	    }
	    return $result;
	}

	private function parseYear ( $string )
	{
	    return (int)end((explode('.', $string)));
	}


}