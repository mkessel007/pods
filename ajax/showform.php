<?php
// Include the MySQL connection
require_once(realpath(dirname(__FILE__) . '/../../../../wp-config.php'));
require_once(realpath(dirname(__FILE__) . '/../Pod.class.php'));

$save = (int) $_POST['save'];
$post_id = (int) $_POST['post_id'];
$datatype = $_POST['datatype'];

// Determine whether the form is public
$is_public = (int) $_POST['public'];

if ($save)
{
    if ($datatype)
    {
        // Get array of datatypes
        $result = mysql_query("SELECT id, name FROM wp_pod_types");
        while ($row = mysql_fetch_assoc($result))
        {
            $datatypes[$row['name']] = $row['id'];
        }

        // Get the datatype ID
        $datatype_id = $datatypes[$datatype];

        // Add data from a public form
        $where = '';
        if (empty($post_id))
        {
            $public_columns = unserialize(stripslashes($_POST['columns']));

            if (!empty($public_columns))
            {
                foreach ($public_columns as $key => $val)
                {
                    $where[] = is_array($public_columns[$key]) ? $key : $val;
                }
                $where = "AND name IN ('" . implode("','", $where) . "')";
            }
        }

        // Get the datatype fields
        $result = mysql_query("SELECT id, label, name, coltype, pickval, sister_field_id, required FROM wp_pod_fields WHERE datatype = $datatype_id $where") or die('Error: Could not get datatype fields');
        while ($row = mysql_fetch_assoc($result))
        {
            if (1 == $row['required'])
            {
                $req[$row['name']] = $row['coltype'];
            }
            $fields[$row['name']] = $row;
        }

        // Verify all required fields
        foreach ($req as $name => $type)
        {
            $val = $_POST[$name];

            if (empty($val))
            {
                die("Error: The $name column is empty.");
            }
            elseif ('date' == $type && false === preg_match("/^(\d{4})-([01][0-9])-([0-3][0-9]) ([0-2][0-9]:[0-5][0-9]:[0-5][0-9])$/", $val))
            {
                die("Error: The $name column is an invalid date.");
            }
            elseif ('num' == $type && !is_numeric($val))
            {
                die("Error: The $name column is an invalid number.");
            }
        }

        // Add the new post
        if (empty($post_id))
        {
            $post_title = mysql_real_escape_string(trim($_POST['name']));
            $post_name = str_replace(' ', '-', strtolower($post_title));
            $post_content = mysql_real_escape_string($_POST['body']);

            $sql = "
            INSERT INTO
                wp_posts (post_author, post_date, post_date_gmt, post_title, post_name, post_content)
            VALUES
                (1, NOW(), UTC_TIMESTAMP(), '$post_title', '$post_name', '$post_content')
            ";
            mysql_query($sql) or die('Error: Could not add public form data');
            $post_id = mysql_insert_id();
        }

        // See if this post_ID already has a module (removing previous module data)
        $result = mysql_query("SELECT row_id, datatype FROM wp_pod WHERE post_id = $post_id LIMIT 1");
        if (0 < mysql_num_rows($result))
        {
            $row = mysql_fetch_assoc($result);
            if ($datatype_id != $row['datatype'])
            {
                mysql_query("DELETE FROM wp_pod WHERE post_id = $post_id");
            }
            else
            {
                $table_row_id = $row['row_id'];
            }
        }

        // Cleanse the $_POST variables
        foreach ($_POST as $key => $val)
        {
            $val = mysql_real_escape_string(stripslashes(trim($val)));
            if ('pick' == $fields[$key]['coltype'])
            {
                // Add rel table entry for each value
                $term_ids = trim($val);
                if (!empty($term_ids))
                {
                    $term_ids = explode(',', $val);
                }
                $field_id = $fields[$key]['id'];
                $pickval = $fields[$key]['pickval'];
                $sister_datatype_id = $datatypes[$pickval];
                $sister_field_id = $fields[$key]['sister_field_id'];
                $sister_field_id = empty($sister_field_id) ? 'NULL' : $sister_field_id;
                $sister_post_ids = array();
                $sister_post_id = 'NULL';

                /*
                ==================================================
                Delete all rels (parent and sister)
                ==================================================
                */
                if ('NULL' != $sister_field_id)
                {
                    // Get sister post IDs (a sister post's sister post is the parent post)
                    $result = mysql_query("SELECT post_id FROM wp_pod_rel WHERE sister_post_id = $post_id");
                    if (0 < mysql_num_rows($result))
                    {
                        while ($row = mysql_fetch_assoc($result))
                        {
                            $sister_post_ids[] = $row['post_id'];
                        }
                        $sister_post_ids = implode(',', $sister_post_ids);

                        // Delete the sister post relationship
                        mysql_query("DELETE FROM wp_pod_rel WHERE post_id IN ($sister_post_ids) AND sister_post_id = $post_id AND field_id = $sister_field_id") or die("Error: Unable to drop sister relationships");
                    }
                }
                mysql_query("DELETE FROM wp_pod_rel WHERE post_id = $post_id AND field_id = $field_id") or die("Error: Unable to drop relationships");
                /*
                ==================================================
                Add relationship values
                ==================================================
                */
                foreach ($term_ids as $term_id)
                {
                    if (!empty($sister_datatype_id))
                    {
                        $result = mysql_query("SELECT post_id FROM wp_pod WHERE datatype = $sister_datatype_id AND row_id = $term_id LIMIT 1") or die('Error: term_id=' . $val);
                        if (0 < mysql_num_rows($result))
                        {
                            $row = mysql_fetch_assoc($result);
                            $sister_post_id = $row['post_id'];
                            mysql_query("INSERT INTO wp_pod_rel (post_id, sister_post_id, field_id, term_id) VALUES ($sister_post_id, $post_id, $sister_field_id, $table_row_id)") or die('Error: Unable to add sister relationships');
                        }
                    }
                    mysql_query("INSERT INTO wp_pod_rel (post_id, sister_post_id, field_id, term_id) VALUES ($post_id, $sister_post_id, $field_id, $term_id)") or die('Error: Unable to add relationships');
                }
            }
            elseif ('datatype' != $key && 'post_id' != $key && 'columns' != $key && 'public' != $key && 'save' != $key)
            {
                if (isset($table_row_id))
                {
                    // Update existing row
                    mysql_query("UPDATE tbl_$datatype SET $key = '$val' WHERE id = $table_row_id LIMIT 1") or die('Error: ' . mysql_error());
                }
                else
                {
                    // Insert new row to data table
                    mysql_query("INSERT INTO tbl_$datatype ($key) VALUES ('$val')") or die('Error: Unable to add new table row');
                    $table_row_id = mysql_insert_id();

                    // Insert new row to wp_pod table
                    mysql_query("INSERT INTO wp_pod (row_id, post_id, datatype) VALUES ('$table_row_id', '$post_id', '$datatype_id')") or die('Error: Unable to add new Pod row');
                }
            }
        }
        // Update wp_pod datatype
        mysql_query("UPDATE wp_pod SET datatype = $datatype_id WHERE row_id = $table_row_id AND post_id = $post_id LIMIT 1") or die('Error: Unable to modify datatype row');
    }
    else
    {
        die('Error: no datatype selected');
    }
}
else
{
    // Show the input form
    $obj = new Pod($datatype);
    echo $obj->showform($post_id, $is_public, $public_columns);
}
