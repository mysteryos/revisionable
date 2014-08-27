<?php namespace Venturecraft\Revisionable;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Revision
 *
 * Base model to allow for revision history on
 * any model that extends this model
 *
 * (c) Venture Craft <http://www.venturecraft.com.au>
 */

class Revision extends Eloquent
{

    public $table = 'revisions';

    protected $revisionFormattedFields = array();
    
    const CREATE = 1;
    const INSERT = 2;
    const UPDATE = 3;
    const DELETE = 4;
    const REMOVE = 5;
    
    private $actions = array(self::CREATE=> 'created', 
                                self::INSERT => 'inserted', 
                                self::UPDATE => 'changed', 
                                self::DELETE => 'deleted', 
                                self::REMOVE => 'removed');    

    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);
    }

    /**
     * Revisionable
     * Grab the revision history for the model that is calling
     * @return array revision history
     */
    public function revisionable()
    {
        return $this->morphTo();
    }

    /**
     * Field Name
     * Returns the field that was updated, in the case that it's a foreighn key
     * denoted by a suffic of "_id", then "_id" is simply stripped
     * @return string field
     */
    public function fieldName()
    {
        if ($formatted = $this->formatFieldName($this->key)) {
            return $formatted;
        } elseif (strpos($this->key, '_id')) {
            return str_replace('_id', '', $this->key);
        } else {
            return $this->key;
        }
    }

    /**
     * Format field name
     * Allow overrides for field names
     **/
    private function formatFieldName($key)
    {
        $related_model = $this->revisionable_type;
        $related_model = new $related_model;
        $revisionFormattedFieldNames = $related_model->getRevisionFormattedFieldNames();

        if (isset($revisionFormattedFieldNames[$key])) {
            return $revisionFormattedFieldNames[$key];
        }

        return false;
    }

    /**
     * Old Value
     * Grab the old value of the field, if it was a foreign key
     * attempt to get an identifying name for the model
     * @return string old value
     */
    public function oldValue()
    {
        return $this->getValue('old');

    }


    /**
     * New Value
     * Grab the new value of the field, if it was a foreign key
     * attempt to get an identifying name for the model
     * @return string old value
     */
    public function newValue()
    {
        return $this->getValue('new');

    }


    /**
     * Resposible for actually doing the grunt work for getting the
     * old or new value for the revision
     * @param  string $which old or new
     * @return string value
     */
    private function getValue($which = 'new')
    {

        $which_value = $which . '_value';

        // First find the main model that was updated
        $main_model = $this->revisionable_type;
        // Load it, WITH the related model
        if ( class_exists($main_model) ) {

            $main_model = new $main_model;

            try {
                if (strpos($this->key, '_id')) {

                    $related_model = str_replace('_id', '', $this->key);

                    // Now we can find out the namespace of of related model
                    if (! method_exists($main_model, $related_model)) {
                        $related_model = camel_case($related_model); // for cases like published_status_id
                        if (! method_exists($main_model, $related_model)) {
                            throw new \Exception('Relation ' . $related_model . ' does not exist for ' . $main_model);
                        }
                    }
                    $related_class = $main_model->$related_model()->getRelated();

                    // Finally, now that we know the namespace of the related model
                    // we can load it, to find the information we so desire
                    $item  = $related_class::find($this->$which_value);

                    if (is_null($this->$which_value) || $this->$which_value == '') {
                        $item = new $related_class;

                        return $item->getRevisionNullString();
                    }
                    if (!$item) {
                        $item = new $related_class;

                        return $this->format($this->key, $item->getRevisionUnknownString());
                    }

                    // see if there's an available revision mutator
                    $revisionmutator = 'get' . studly_case($this->key) . 'RevisionAttribute';
                    if (method_exists($item, $revisionmutator)) {
                        return $this->format($item->$mutator($this->key), $item->identifiableName());
                    }
                    else
                    {
                        // see if there's an available default eloquent mutator
                        $mutator = 'get' . studly_case($this->key) . 'Attribute';
                        if (method_exists($item, $mutator)) {
                            return $this->format($item->$mutator($this->key), $item->identifiableName());
                        }                        
                    }
                    
                    return $this->format($this->key, $item->identifiableName());
                }

            } catch (\Exception $e) {
                // Just a failsafe, in the case the data setup isn't as expected
                // Nothing to do here.
                Log::info('Revisionable: ' . $e);
            }

            // if there was an issue
            // or, if it's a normal value

            // see if there's an available revision mutator
            $revisionMutator = 'get' . studly_case($this->key) . 'RevisionAttribute';
            if (method_exists($main_model, $revisionMutator)) {
                return $this->format($this->key, $main_model->$revisionMutator($this->$which_value));
            }
            else
            {
                //else fallback to default eloquent mutator
                $mutator = 'get' . studly_case($this->key) . 'Attribute';
                if (method_exists($main_model, $mutator)) {
                    return $this->format($this->key, $main_model->$mutator($this->$which_value));
                }
            }

        }

        return $this->format($this->key, $this->$which_value);

    }

    /**
     * User Responsible
     * @return User user responsible for the change
     */
    public function userResponsible()
    {
        if (class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')) {
            return $class::findUserById($this->user_id);
        } else {
            $user_model = Config::get('auth.model');

            return $user_model::find($this->user_id);
        }
    }

    /*
     * Egzamples:
    array(
        'public' => 'boolean:Yes|No',
        'minimum'  => 'string:Min: %s'
    )
     */
    /**
     * Format the value according to the $revisionFormattedFields array
     *
     * @param  $key
     * @param  $value
     *
     * @return string formated value
     */
    public function format($key, $value)
    {
        $related_model                   = $this->revisionable_type;
        $related_model                   = new $related_model;
        $revisionFormattedFields = $related_model->getRevisionFormattedFields();

        if (isset($revisionFormattedFields[$key])) {
            return FieldFormatter::format($key, $value, $revisionFormattedFields);
        } else {
            return $value;
        }
    }
    
    /*
     * Create a preformatted revision string
     */
    public function getRevisionString()
    {
        switch($this->action)
        {
            //Creating model
            case self::CREATE:
                
                break;
            //Inserting a value
            case self::INSERT:
                
                break;
            //Updating a value
            case self::UPDATE:
                
                break;
            //Deleting a value
            case self::DELETE:
                return $this->actions[$this->action]." (".$revisionClassName.") ID:".$this->revisionable_id;
                break;
            //Deleting a model
            case self::REMOVE:
                $related_model     = $this->revisionable_type;
                $related_model     = new $related_model;
                $revisionClassName = $related_model->getRevisionClassName() ? $related_model->getRevisionClassName() : $this->revisionable_type;
                
                return $this->actions[$this->action]." (".$revisionClassName.") ID:".$this->revisionable_id;
            default:
                return "created unknown revision";
                break;
        }
    }
    
    /*
     * Accessor
     */
    
    public function className()
    {
        $related_model                   = $this->revisionable_type;
        $related_model                   = new $related_model;
        return $related_model->getRevisionClassName() ? $related_model->getRevisionClassName() : $this->revisionable_type;
    }
    
    /**
     * Get Primary Identifier's Value
     * 
     * @param alternativeIdentifier string
     * @return @string/@bool
     **/
    public function primaryIdentifierValue($alternativeIdentifier=false)
    {
        /*
         * Get Primary Identifier from Revisionable model if alternative is not set
         */
        if($alternativeIdentifier === false)
        {
            $related_model = $this->revisionable_type;
            $related_model = new $related_model;
            $primaryIdentifier = $related_model->getRevisionPrimaryIdentifier();
        }
        else
        {
            $primaryIdentifier = $alternativeIdentifier;
        }
        
        
        //Verify if primary identifier attribute has been set in revisionable model
        if($primaryIdentifier)
        {
            $related_model_object = $this->revisionable()->withTrashed()->first();
            if(count($related_model_object))
            {
                /*
                 * Check if attribute is present.
                 */
                if(isset($related_model_object->{$primaryIdentifier}))
                {
                    return $related_model_object->{$primaryIdentifier};
                }
                else
                {
                    throw New \RuntimeException("Primary Identifier Attribute not set in revisionable model '{$related_model}'");
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get Primary Identifier's Human Readable Name
     * 
     * @param string $alternativeIdentifier
     * @return string/boolean
     */
    public function primaryIdentifierName($alternativeIdentifier=false)
    {
        if($alternativeIdentifier === false)
        {
            $related_model = $this->revisionable_type;
            $related_model = new $related_model;
            $primaryIdentifier = $related_model->getRevisionPrimaryIdentifier();
        }
        else
        {
            $primaryIdentifier = $alternativeIdentifier;
        }
        
        if($primaryIdentifier)
        {        
            return $this->formatFieldName($primaryIdentifier);
        }
        
        return false;
    }
}
