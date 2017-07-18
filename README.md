PHP&#xfeff;Debug&#xfeff;Console
===============

Browser/javascript like console class for PHP

**Website/Usage/Examples:** http://www.bradkent.com/?page=php/debug

* PHP port of the [javascript web console api](https://developer.mozilla.org/en-US/docs/Web/API/console)
* multiple output paths
    * [ChromeLogger](https://craig.is/writing/chrome-logger/techspecs)
    * HTML
    * [FirePHP](http://www.firephp.org/)  (no FirePHP dependency!)
    * Plain text / file
    * &lt;script&gt;
    * "plugin"
* custom error handler
	* errors (even fatal) are captured / logged / displayed
	* send error notices via email (throttled as to not to send out a flood of emails)
* password protected
* send debug log via email

![Screenshot of PHPDebugConsole's Output](http://www.bradkent.com/images/bradkent.com/php/screenshot_1.3.png)

### Installation
This library requires PHP 5.4 (function array dereferencing, closure $this support) or later and has no userland dependencies.

It is installable and autoloadable via [Composer](https://getcomposer.org/) as [bdk/debug](https://packagist.org/packages/bdk/debug).

```json
{
    "require": {
        "bdk/debug": "~1.3",
    }
}
```
Alternatively, [download a release](https://github.com/bkdotcom/debug/releases) or clone this repository, then require or include its `Debug.php` file

See http://www.bradkent.com/?page=php/debug for more information

### Methods

* log
* info
* warn
* error
* assert
* count
* group
* groupCollapsed
* groupEnd
* table
* time
* timeEnd
* *&hellip; [more](http://www.bradkent.com/?page=php/debug#docs-methods)*

### Tests / Quality
[![Build Status](https://travis-ci.org/bkdotcom/PHPDebugConsole.svg?branch=master)](https://travis-ci.org/bkdotcom/PHPDebugConsole)  
[![SensioLabsInsight](https://insight.sensiolabs.com/projects/789295b4-6040-4367-8fd5-b04a6f0d7a0c/mini.png)](https://insight.sensiolabs.com/projects/789295b4-6040-4367-8fd5-b04a6f0d7a0c)

### Changelog
http://www.bradkent.com/?page=php/debug#docs-changelog
