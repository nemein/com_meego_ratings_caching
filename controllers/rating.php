<?php
/**
 * @package com_meego_ratings
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */
class com_meego_ratings_caching_controllers_rating extends com_meego_ratings_controllers_rating
{
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
     * Calcualtes the average rating and passes that to the view too ($this->data['average']).
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
    }

    /**
     * Sets the average rating of the package
     * Sets the flag showing if the package was ever rated or not
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

        $this->data['repository'] = midgard_object_class::get_object_by_guid($this->data['to']->repository);

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
                new midgard_query_value($this->data['repository']->guid)
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
        if (count($cache))
        {
            $cache->ratings = $num_of_valid_ratings;
            $cache->ratingvalue = $this->data['average'];
            $cache->comments = $num_of_comments;
            $cache->update();
        }
        else
        {
            $cache_record = new com_meego_package_statistics_calculated();
            $cache_record->packagename = $this->data['to']->name;
            $cache_record->ratings = $num_of_valid_ratings;
            $cache_record->ratingvalue = $this->data['average'];
            $cache_record->comments = $num_of_comments;
            $cache_record->create();
        }

        $this->get_stars($this->data['average']);
    }
}