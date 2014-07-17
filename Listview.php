<?php
class Listview
{
    public $sql = ''; // The raw sql that is run to generate the list
    public $results = NULL; // The results of the query get saved here
    public $download_link = array(); // Are we including a download csv link?
    public $display_header = TRUE; // Display a header row of the column names
    public $class = 'list'; // The table class name, if any
    public $defaults = array(); // An array of default substitutes for the query
    private $param_key = 1; // A unique key generator for the params array
    public $params = array(); // An associative array of parameters bound the sql query
    public $substitutions = array(); // An array of substitutes for the query, typically can pass the $_GET to this
    public $no_results = 'There are no results to display'; // The text to display if there are no results
    public $display_count = FALSE; // Optional array that generates a paragraph above the results showing the total record count
    public $separator = '|';
    public $empty_field = 'none';
    public $field_formats = array();
    public $field_classes = array();
    public $row_classes = array();
    public $items_per_page = FALSE;
    public $page = 0;
    public $total_records = NULL;
    public $pre_query = NULL;
    public $post_query = NULL;
    private $db = NULL;
    public $csv_format = false;
    public $total_row = false;
    public $ignore_columns = array();

    public function __construct($query = '')
    {
        if(is_string($query))
            $this->sql = $query;
        else if (is_array($query))
            $this->results = $query;
        $this->get_query_builder();
    }

    // Query helpers
    private function get_query_builder()
    {
        return $this->db or $this->db = data::get_command();
    }

    private function query_rows($query)
    {
        return $this->db->setText($query)->queryAll(true, $this->params);
    }

    private function query_scalar($query)
    {
        return $this->db->setText($query)->queryScalar($this->params);
    }

    // Pagination
    public function detect_page()
    {
        $this->page = (int) data::value($_GET, 'page', 1);
    }

    public function paginate_query($page)
    {
        if(stripos($this->sql, 'limit') === FALSE)
            $this->sql .= ' LIMIT '.$this->items_per_page;
        if(stripos($this->sql, 'offset') === FALSE AND $page)
            $this->sql .= ' OFFSET '.(($page-1) * $this->items_per_page);
        return $this->sql;
    }

    // Total records
    public function count_records()
    {
        $query = $this->sql;
        // Chop off the limit and offset
        $position = stripos($query, ' limit ');
        if($position !== FALSE)
            $query = substr($query, 0, $position);
        // Get the total number of records
        return $this->total_records = $this->query_scalar('SELECT COUNT(*) as total from ('.$query.') as total_records');
    }

    public function get_substitutions()
    {
        return array_merge($this->defaults, $this->substitutions);
    }

    public function perform_substitutions()
    {
        return $this->sql_substitution($this->get_substitutions());
    }

    public function sql_substitution($array)
    {
        foreach($array as $source => $target)
        {
            if(strpos($this->sql, "{{$source}}") === false)
                continue;
            if(in_array($source, array('sort','order')))
                $this->sql = str_replace("{{$source}}", $target, $this->sql);
            else
            {
                $key = ':param'.($this->param_key++);
                $this->sql = str_replace("{{$source}}", $key, $this->sql);
                $this->params[$key] = $target;
            }
        }
        return $this->sql;
    }

    public function execute_sql()
    {
        $this->run_pre_query();
        $this->perform_substitutions();
        if($this->items_per_page)
        {
            $this->count_records();
            $this->detect_page();
            $this->paginate_query($this->page);
        }
        $this->results = $this->query_rows($this->sql);
        if($this->total_records === NULL)
            $this->total_records = count($this->results);
        return $this->results;
    }

    public function run_pre_query()
    {
        if( ! $this->pre_query)
            return;
        if(is_string($this->pre_query))
        {
            $defaults = array();
            $substitutions = array();
            eval($this->pre_query);
            if(isset($defaults) and is_array($defaults))
                $this->defaults = array_merge($this->defaults, $defaults);
            if(isset($substitutions) and is_array($substitutions))
                $this->substitutions = array_merge($this->substitutions, $substitutions);
        }
        else if(is_callable($this->pre_query))
            call_user_func_array($this->pre_query, array(&$this));
        // Datetime timezone conversion
        $pattern = '/\b\w+\.\w+_(?:time|date)\b/';
        $this->sql = preg_replace($pattern, "convert_tz($0, 'gmt', 'est5edt')", $this->sql);
    }

    public static function prepare_links($array)
    {
        $links = array();
        for($i = 0; $i < count($array)-1; $i+=2)
        {
            $link = self::prepare_param($array[$i]) . ",'|',";
            $url_segments = array_map(function($str) { return self::prepare_param($str); }, explode('/', $array[$i+1]));
            $link .= implode(",'/',", $url_segments);
            $links[] = $link;
        }
        return 'CONCAT('.implode(",'|',", $links).')';
    }

    public static function prepare_param($param)
    {
        if(is_array($param))
        {
            $params = array();
            foreach($param as $p)
                if($p)
                    $params[] = self::prepare_param($p);
            return implode(",",$params);
        }
        else if(is_string($param))
            return (strpos($param, '.') !== FALSE AND preg_match('/[\w.]+/', $param)) ? "$param" : "'{$param}'";
        else if(is_int($param))
            return $param;
        else if($param)
            return $param;
        else
            return 'NULL';
    }

    // Run the query and get the results
    public function prepare()
    {
        if($this->results === NULL)
            return $this->execute_sql();
        if($this->total_records === NULL)
            $this->total_records = count($this->results);
        return $this->results;
    }

    // Detects the format and returns it, modifying the original value by stripping out the detected format
    public function detect_format(& $value)
    {
        // Look for formats we know what to do with
        foreach(array('currency','number','date','percentage','string','title') as $type)
        {
            $search = "{{$type}}";
            if(strpos($value, $search) !== FALSE)
            {
                $value = trim(str_replace($search, '', $value));
                return $type;
            }
        }
        // Return any other formats if the usuals are not detected
        if(preg_match('/{([^}]+)}/', $value, $matches))
        {
            $value = str_replace($matches[0], '', $value);
            return $matches[1];
        }
        return false;
    }

    public function decode_format(& $format)
    {
        $fields = explode('-', $format);
        $format = $fields[0];
        return count($fields) > 1 ? $fields[1] : false;
    }

    public function format($value, $format = '')
    {
        if( ! is_string($format) or $format === '' or $format === 'none')
            $format = $this->detect_format($value);
        if(!$format)
        {
            if(!$this->csv_format and (is_int($value) or ctype_digit($value)))
                return number_format($value, 0);
            return $value;
        }
        $options = $this->decode_format($format);
        if($format === 'currency')
            return '$'.number_format((double) $value, 2);
        if($format === 'date')
            return (is_int($value) OR ctype_digit($value)) ? strtotime($value) : date('M j, Y', $value);
        if($format === 'time' AND (is_int($value) OR ctype_digit($value)))
            return date('g:ia', $value);
        if($format === 'percentage')
            return number_format(100 * $value, $options ? $options : 2).'%';
        if($format === 'pad')
            return str_pad($value, $options ? $options : 3, '0', STR_PAD_LEFT);
        if($format === 'title')
            return ucwords(str_replace("_", " ", (string) $value));
        if($format === 'string')
            return (string) $value;
        if($format === 'number')
            return number_format($value ? $value : 0, $options ? $options : 0);
        if(is_string($format))
            return html::pluralize($value, $format);
        return $value;
    }

    public function detect_links($value)
    {
        $links = array();
        $fields = explode('|', $value);
        for($i = 0; $i < count($fields); $i+=2)
            $links[] = array('text'=>$fields[$i], 'href'=>count($fields) > $i+1 ? $fields[$i+1] : '');
        return $links;
    }

    public function build_link($link, $format = true)
    {
        if( ! is_array($link) )
            return $link;
        $text = $href = $new_window = '';
        extract($link);
        if( ! isset($text) or $text === '')
            return $format === 'number' ? '0' : '';
        if($href)
        {
            $new_window = $href[0] === '!' ? "target='_blank'" : '';
            $href = $this->build_url(ltrim($href, '!'));
        }
        if($format)
            $text = $this->format($text, $format);
        return $href ? "<a href='{$href}' {$new_window}>{$text}</a>" : $text;
    }

    public function build_links($links, $format = true, $separator = '|')
    {
        $link_list = array();
        foreach($links as $link)
            $link_list[] = $this->build_link($link, $format);
        return implode(" {$separator} ", $link_list);
    }

    protected function get_value($value, $i, $print_view = false)
    {
        if($value === '' AND ! $print_view)
            return $this->empty_field;
        $links = $this->detect_links($value);
        $format = isset($this->field_formats[$i]) ? $this->field_formats[$i] : true;
        $formatted = $this->build_links($links, $format, $this->separator);
        if($print_view)
            $formatted = strip_tags($formatted);
        return $formatted;
    }

    public function render()
    {
        $this->prepare();

        // If you set download link to true, this takes care of the downloading if ?download=1
        if($this->download_link AND isset($_GET['download']) AND $_GET['download'])
        {
            $this->export(Request::current()->action());
            return;
        }

        // Post query callback
        if(is_callable($this->post_query))
            call_user_func_array($this->post_query, array(&$this->results));

        // Build the html
        $html = $this->render_list();
        $html .= $this->render_pagination();
        return $html;
    }

    protected function current_url()
    {
        $controller = Yii::app()->controller;
        return "{$controller->id}/{$controller->action->id}";
    }

    protected function build_url($url, $params = array(), $output = false)
    {
        if(substr($url,0,4) == 'http')
            return $url;
        $segments = explode('/',$url);
        if($segments[0] == 'admin_new' or (count($segments) > 1 and $segments[1] == 'admin_new'))
            return html::normalizeUrl($url, $params);
        if(count($segments) == 2 and ctype_digit($segments[count($segments)-1]))
            $url = Yii::app()->controller->id . "/{$url}";
        $data = array_merge(array($url), $params);
        return html::normalizeUrl($data);
    }

    protected function prepare_tag($tag, $defaults)
    {
        if(!$tag) return;
        if(!is_array($tag))
            $tag = array();
        $tag = array_merge($defaults, $tag);
        return $tag;
    }

    protected function render_link($link)
    {
        return $this->render_html_tag('a', $link);
    }

    protected function render_html_tag($tag, $attributes = array(), $ending_tag = true)
    {
        $html_attributes = array();
        $text = isset($attributes['text']) ? $attributes['text'] : '';
        unset($attributes['text']);
        foreach($attributes as $attribute => $value)
            $html_attributes[] = " {$attribute}={$value}";
        $ending_tag = $ending_tag ? "</{$tag}>" : '';
        return "<{$tag}".implode('', $html_attributes).">{$text}{$ending_tag}";
    }

    protected function render_list()
    {
        $list = & $this->results;
        $html = '';
        // Download link
        if($this->download_link)
            $html .= $this->render_link($this->prepare_tag($this->download_link, array(
                'text'=>'Download',
                'href'=>$this->build_url($this->current_url(), array('download'=>'1'))
            )));
        // The table begins
        $html .= '<table class="'.$this->class.'" cellpadding="0" cellspacing="0" border="0">';
        if(count($list) == 0)
            $this->class .= ' no-results';
        // Display count
        if($this->display_count)
        {
            $p = $this->render_html_tag('p', $this->prepare_tag($this->display_count, array(
                'text'=>$this->format($this->total_records, 'records'),
                'class'=>''
            )));
            $columns = count($list) > 0 ? count(array_keys($this->results[0])) : 1;
            $html .= "<tr><td colspan='{$columns}' class='total-results'>{$p}</td></tr>";
        }
        // Are there rows?
        if(count($list) == 0)
        {
            $html .= '<tr><td>'.$this->no_results.'</td></tr>';
        }
        else
        {
            // Are we adding a header row?
            if($this->display_header)
            {
                $first_row = $list[0];
                $headers = is_array($list[0]) ? array_keys($list[0]) : false;
                if($headers)
                {
                    $html .= '<tr>';
                    foreach($headers as $header)
                        if( ! in_array($header, $this->ignore_columns) )
                            $html .= '<th>'.ucwords(str_replace("_", " ", $header)).'</th>';
                    $html .= '</tr>';
                }
            }
            $first = TRUE;
            $even = TRUE;
            $totals = array();
            for($i = 0; $i < count($list[0]); $i++)
                $totals[$i] = 0;
            // Render the row
            foreach($list as $record)
            {
                $record = (object)$record;
                $classes = $this->row_classes;
                $classes[] = $even ? 'even' : 'odd';
                if($first)
                    $classes[] = 'first';
                $row = array('class'=>implode(' ', $classes));
                if(isset($record->id))
                    $row['id'] = "row-{$record->id}";
                $html .= $this->render_html_tag('tr', $row, false);
                $i = 0;
                // Render each field
                foreach($record as $name => $value)
                {
                    if( ! in_array($name, $this->ignore_columns) )
                    {
                        $value = $this->get_value($value, $i);
                        if($this->total_row)
                            $totals[$i] += $this->get_number($value);
                        $field = array('class'=>isset($this->field_classes[$i]) ? $this->field_classes[$i] : '', 'text'=>$value);
                        $html .= $this->render_html_tag('td', $field);
                    }
                    $i++;
                }
                $html .= '</tr>';
                $even = $even ? FALSE : TRUE;
                $first = FALSE;
            }
            if($this->total_row)
            {
                $html .= '<tr class="totals"><th>Total</th>';
                for($i = 1; $i < count($totals); $i++)
                {
                    $values = array_values($list[0]);
                    $total = $totals[$i] ? $this->format($totals[$i], 'number') : '0';
                    $html .= "<th>{$total}</th>";
                }
                $html .= '</tr>';
            }
        }
        // Table end
        $html .= '</table>';
        return $html;
    }

    protected function get_number($number)
    {
        if(ctype_digit($number))
            return $number;
        if(strpos($number, '<') !== FALSE and preg_match('/<a[^>]*>([^<]+)<\/a>/', $number, $match))
            return $match[1];
        return 0;
    }

    protected function render_pagination()
    {
        if( ! ($this->items_per_page AND $this->total_records) )
            return;
        $html = '';
        $total_pages = ceil($this->total_records / $this->items_per_page);
        if($total_pages > 1)
        {
            $current = $this->page;
            $previous = max(1, $current - 1);
            $next = min($total_pages, $current + 1);
            $html = '<p class="pagination">';
            $url = '';
            if($current > 1)
                $html .= $this->render_link(array('text'=>'&laquo;','class'=>'previous',
                    'href'=>$this->build_url('', array_merge($_GET, array('page'=>$previous)))));
            $html .= $this->render_html_tag('span', array('text'=>"{$current} of {$total_pages}</span>"));
            if($current != $total_pages)
                $html .= $this->render_link(array('text'=>'&raquo;','class'=>'next',
                    'href'=>$this->build_url('', array_merge($_GET, array('page'=>$next)))));
            $html .= '</p>';
        }
        return $html;
    }

    public function export($filename='export')
    {
        $this->items_per_page = FALSE;
        $request = Request::current();
        ob_clean();
        $request->response()->body($this->csv());
        $request->response()->send_file(TRUE, str_replace(array(' ','_'),'-',preg_replace('/[^0-9a-z_-]/','',strtolower($filename))).'.csv');
        $request->redirect($request->detect_uri(),200);
    }
    public function csv()
    {
        $this->csv_format = true;
        $list = $this->prepare(true);
        // Begin building the output
        $file = '';
        $ignore_field = NULL;
        // Are we adding a header row?
        if($this->display_header)
        {
            $first = 1;
            $i = 0;
            if($this->results)
                $current = $list[0];
            else
                $current = $list->current();
            foreach(array_keys($current) as $header)
            {
                if(strtolower($header) == 'actions')
                    $ignore_field = $i;
                else
                    $file .= ($first ? '' : ',').'"'.ucwords(str_replace(array("_",'"'), array(" ","'"), $header)).'"';
                $first = 0;
                $i++;
            }
            $file .= "\n";
        }
        // Render each row
        foreach($list as $record)
        {
            $i = 0;
            // Render each field
            foreach($record as $value)
            {
                if($i === $ignore_field)
                    continue;
                $value = $this->get_value($value, $i, 1);
                $value = str_replace('"',"'",$value);
                $file .= ($i == 0 ? '' : ',').'"'.$value.'"';
                $i++;
            }
            if($record != end($list))
                $file .= "\n";
        }
        return $file;
    }
}
