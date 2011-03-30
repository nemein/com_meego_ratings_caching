<?php
/**
 * @package com_meego_ratings
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */
class com_meego_ratings_caching_controllers_rating extends com_meego_ratings_controllers_rating
{

    public function load_form()
    {
       $this->form = midgardmvc_helper_forms::create('com_meego_ratings_rating');
//        $this->request->resolve_node('/ratings/create');
        $this->form->set_action
        (
            midgardmvc_core::get_instance()->dispatcher->generate_url
            (
                'rating_create', array
                (
                    'to' => $this->data['parent']->guid
                ),
                $this->request
            )
        );

        if ($this->request->is_subrequest())
        {
            // rating posting form is in a dynamic_load, set parent URL for redirects
            $root_request = midgardmvc_core::get_instance()->context->get_request(0);
            $field = $this->form->add_field('relocate', 'text', false);
            $field->set_value($root_request->get_path());
            $field->set_widget('hidden');
        }

        // Basic element information
        $field = $this->form->add_field('rating', 'integer');

        // Default rating is 0
        $field->set_value(0);

        if ($this->object->rating > 0)
        {
            $field->set_value($this->object->rating);
        }
        $widget = $field->set_widget('eu_urho_widgets_starrating');
        // @todo: get the rating options from configuration
        $widget->add_option('Very bad', 1);
        $widget->add_option('Poor', 2);
        $widget->add_option('Average', 3);
        $widget->add_option('Good', 4);
        $widget->add_option('Excellent', 5);

        $field = $this->form->add_field('comment', 'text');
        $field->set_value('');
        $widget = $field->set_widget('textarea');
    }

    /**
     * Only process the creation if rating is not null or 0
     */
    public function post_create(array $args)
    {
        $this->get_create($args);
        try
        {
            $this->process_form();

            if (   $this->object->rating
                || $this->object->comment)
            {
                $this->object->create();
                $args = array();
                $args['to'] = $this->object->to;
                $this->calculate_average($args);
            }
            // TODO: add uimessage of $e->getMessage();
            $this->relocate_to_read();
        }
        catch (midgardmvc_helper_forms_exception_validation $e)
        {
            // TODO: UImessage
        }
    }

    /**
     * Retrieves all ratings belonging to the object having the guid: $this->data['to'].
     *
     * Passes all ratings to the view ($this->data['ratings']).
     * Calculates the average rating and passes that to the view too ($this->data['average']).
     * Sets the rated flag ($this->data['rated']) to show if object was ever rated or not.
     * Sets the can_post flag ($this->data['can_post']), so that the view can determine
     * whether to show a POST form or not.
     *
     * @param array arguments
     */
    public function get_ratings(array $args)
    {
        $this->get_average($args);

        if (midgardmvc_core::get_instance()->authentication->is_user())
        {
            $this->data['can_post'] = true;
        }
        else
        {
            $this->data['can_post'] = false;
        }

        // @todo: can't add elements to head from here.. why?

        // Enable jQuery in case it is not enabled yet
        midgardmvc_core::get_instance()->head->enable_jquery();

        // Add rating CSS
        $css = array
        (
            'href' => MIDGARDMVC_STATIC_URL . '/com_meego_ratings/js/jquery.rating/jquery.rating.css',
            'rel' => 'stylesheet'
        );
        midgardmvc_core::get_instance()->head->add_link($css);
        // Add rating js
        midgardmvc_core::get_instance()->head->add_jsfile(MIDGARDMVC_STATIC_URL . '/com_meego_ratings/js/jquery.rating/jquery.rating.pack.js', true);

        $this->data['to'] = midgard_object_class::get_object_by_guid($args['to']);

        $storage = new midgard_query_storage('com_meego_ratings_rating_author');
        $q = new midgard_query_select($storage);
        $q->set_constraint
        (
            new midgard_query_constraint
            (
                new midgard_query_property('to', $storage),
                '=',
                new midgard_query_value($this->data['to']->guid)
            )
        );

        $q->add_order(new midgard_query_property('posted', $storage), SORT_DESC);
        $q->execute();
        $ratings = $q->list_objects();
        $this->data['ratings'] = array();

        if (count($ratings))
        {
            $this->data['rated'] = true;
            foreach ($ratings as $rating)
            {
                $rating->stars = '';
                // only return ratings with comments
                if ($rating->ratingcomment)
                {
                    $comment = new com_meego_comments_comment($rating->ratingcomment);
                    $rating->ratingcommentcontent = $comment->content;

                    // add a new property containing the stars to the rating object
                    $rating->stars = $this->draw_stars($rating->rating);
                    // pimp the posted date
                    $rating->date = gmdate('Y-m-d H:i e', strtotime($rating->posted));
                    array_push($this->data['ratings'], $rating);
                }
            }
        }

    }

    /**
     * Calculates the average rating of the package
     * Sets the flag showing if the package was ever rated or not
     */
    public function calculate_average(array $args)
    {
        $this->data['to'] = midgard_object_class::get_object_by_guid($args['to']);

        if ( ! $this->data['to'] )
        {
            throw new midgardmvc_exception_notfound("rating target not found");
        }

        $this->data['repository'] = new com_meego_repository($this->data['to']->repository);

        parent::get_read($args);

        $this->data['ratings'] = array();
        $this->data['average'] = 0;
        $this->data['rated'] = false;

        $storage = new midgard_query_storage('com_meego_ratings_rating_author');
        $q = new midgard_query_select($storage);
        $q->set_constraint
        (
            new midgard_query_constraint
            (
                new midgard_query_property('to', $storage),
                '=',
                new midgard_query_value($this->data['to']->guid)
            )
        );

        $q->add_order(new midgard_query_property('posted', $storage), SORT_DESC);
        $q->execute();
        $ratings = $q->list_objects();

        $storage = new midgard_query_storage('com_meego_package_statistics_calculated');
        $q = new midgard_query_select($storage);
        $q->set_constraint
        (
            new midgard_query_constraint
            (
                new midgard_query_property('repository', $storage),
                '=',
                new midgard_query_value($this->data['repository']->id)
            )
        );

        $q->execute();
        $cache = $q->list_objects();

        $sum = 0;
        // only contains ratings where rating is not 0
        $num_of_valid_ratings = 0;
        $num_of_comments = 0;
        if (count($ratings))
        {
            $this->data['rated'] = true;
            foreach ($ratings as $rating)
            {
                $rating->stars = '';
                if ($rating->ratingcomment)
                {
                    $comment = new com_meego_comments_comment($rating->ratingcomment);
                    $rating->ratingcommentcontent = $comment->content;
                    $num_of_comments++;
                }
                if (   $rating->rating
                    || $rating->ratingcomment)
                {
                    // add a new property containing the stars to the rating object
                    $rating->stars = $this->draw_stars($rating->rating);
                    // pimp the posted date
                    $rating->date = gmdate('Y-m-d H:i e', strtotime($rating->posted));

                    $sum += $rating->rating;
                    if ($rating->rating)
                    {
                        // count only non zero ratings
                        ++$num_of_valid_ratings;
                    }
                }
                array_push($this->data['ratings'], $rating);
            }

            if ($num_of_valid_ratings)
            {
                $this->data['average'] = round($sum / $num_of_valid_ratings, 1);
            }
        }

        if (   count($cache) > 0
            && $cache[0]->ratings != $num_of_valid_ratings)
        {
            $cache[0]->ratings = $num_of_valid_ratings;
            $cache[0]->ratingvalue = $this->data['average'];
            $cache[0]->comments = $num_of_comments;
            $cache[0]->update();
        }
        else
        {
            $cache_record = new com_meego_package_statistics_calculated();
            $cache_record->packagename = $this->data['to']->name;
            $cache_record->repository = $this->data['repository']->id;
            $cache_record->ratings = $num_of_valid_ratings;
            $cache_record->ratingvalue = $this->data['average'];
            $cache_record->comments = $num_of_comments;
            $cache_record->create();
        }
    }

    /**
     * Sets the average rating of the package
     * Sets $this->data['stars'] that can be directly put to pages showing
     * the stars
     */
    public function get_average(array $args)
    {
        $this->data['to'] = midgard_object_class::get_object_by_guid($args['to']);

        if ( ! $this->data['to'] )
        {
            throw new midgardmvc_exception_notfound("rating target not found");
        }

        $this->data['repository'] = new com_meego_repository($this->data['to']->repository);

        parent::get_read($args);

        $storage = new midgard_query_storage('com_meego_package_statistics_calculated');
        $q = new midgard_query_select($storage);
        $q->set_constraint
        (
            new midgard_query_constraint
            (
                new midgard_query_property('repository', $storage),
                '=',
                new midgard_query_value($this->data['repository']->id)
            )
        );

        $q->execute();
        $cache = $q->list_objects();

        $this->data['average'] = 0;
        $this->data['numberofratings'] = 0;
        $this->data['numberofcomments'] = 0;
        $this->data['rated'] = false;

        //load data from cache
        if (count($cache) > 0)
        {
            $this->data['average'] = $cache[0]->ratingvalue;
            $this->data['numberofratings'] = $cache[0]->ratings;
            $this->data['numberofcomments'] = $cache[0]->comments;
            $this->data['rated'] = true;
        }

        $this->get_stars($this->data['average']);
    }
}