
# wbLess Automatic Less Compiler for Joomla!

This plugin will scan and compile less files automatically when Joomla executes.

## Configuration

**Watch Paths** 
These are paths that are examined for less files.  Watch paths include all sub-folders. Default: /templates/{$template}/less

**Trigger Domains**
These are domain hosts that can be used to limit when the plugin runs. Example: sandbox.website.com

**Compress Output**
Compress the CSS output files.

**Watch Minimum Dependency**
Are wildcard rules used when rules for a specific file exist?

**Trigger Event**
When does the less examination and compiler run?

## Usage

> The less compiler will ignore files starting with an underscore like _variables.less

Once installed and configured, the plugin will automatically compare the file modified dates of a `.less` file and it's companion `.css`.  The less file is compiled if the css is newer.

The less compiler does not read less files for dependencies, so they must be specified by creating `.lessrc` instruction files.

In the following example, the `template.less` file depends on `_variables.less`, and the `template.lessrc` file defines that dependency.

templates/example/less/_variables.less

    @fontColor: black;

templates/example/less/template.less

    @import "_variables.less";
    body {
      color: @fontColor;
    }

templates/example/less/template.lessrc

    {
    	"import": [
    		"_variables.less"
    	]
    }

Alternatively each folder may contain a single `.lessrc` file that defines rules for the entire folder.  In the following example the `*` wildcard specifies that all files depend on `_variables.less`, while `editor.less` has additional dependencies.

templates/example/less/.lessrc

    {
	    "files": [
		    {
			    "file": "*",
			},
		    {
			    "file": "editor.less",
			    "import": "../system/less/_variables.less"
			},
	    ],
    	"import": [
    		"_variables.less"
    	]
    }
