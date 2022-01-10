# Custom Markdown-filters

Have your class extend the ```/Plugins/AbstractParsedownFilter.php``` class and implement the ```__invoke``` method.
Place your class in this folder. Filters are executed in no particular order.  

ParsedownFilters are used to parse articles and reactions each time they are committed from the editor.

Your filter will be executed for every XHTML element that will be generated from the 
Markdown, just before it is outputted. Your filter can change the element. This is the general structure for the elements:

    [
        "name" => "iframe",
        "title" => ...,
        "text" => ...,
        "attributes" => [
            "src" => ...,
            "class" => ...,
        ],
    ]

