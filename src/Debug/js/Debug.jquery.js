/**
 * Enhance debug output
 *    Add expand/collapse functionality to groups and arrays
 *    Add FontAwesome icons
 */

(function($, clipboard) {

	var options = {
			fontAwesomeCss: "//maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css",
			jQuerySrc: "//ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js",
			clipboardSrc: "//cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.0/clipboard.min.js",
			classes: {
				expand : "fa-plus-square-o",
				collapse : "fa-minus-square-o",
				empty : "fa-square-o"
			},
			iconsMisc: {
				".timestamp" : '<i class="fa fa-calendar"></i>'
			},
			iconsObject: {
				"> .info.magic" :				'<i class="fa fa-fw fa-magic"></i>',
				"> .method.magic" :				'<i class="fa fa-fw fa-magic" title="magic method"></i>',
				"> .method.deprecated" :		'<i class="fa fa-fw fa-arrow-down" title="Deprecated"></i>',
				"> .property.debuginfo-value" :	'<i class="fa fa-eye" title="via __debugInfo()"></i>',
				"> .property.excluded" :		'<i class="fa fa-eye-slash" title="not included in __debugInfo"></i>',
				"> .property.private-ancestor" :'<i class="fa fa-lock" title="private ancestor"></i>',
				"> .property > .t_modifier_magic" :		  '<i class="fa fa-magic" title="magic property"></i>',
				"> .property > .t_modifier_magic-read" :  '<i class="fa fa-magic" title="magic property"></i>',
				"> .property > .t_modifier_magic-write" : '<i class="fa fa-magic" title="magic property"></i>',
				".toggle-vis[data-toggle=private]" :	'<i class="fa fa-user-secret"></i>',
				".toggle-vis[data-toggle=protected]" :	'<i class="fa fa-shield"></i>',
				".toggle-vis[data-toggle=excluded]" :   '<i class="fa fa-eye-slash"></i>'
			},
			// debug methods (not object methods)
			iconsMethods: {
				".group-header" :		'<i class="fa fa-lg fa-minus-square-o"></i>',
				".m_assert" :			'<i class="fa-lg"><b>&ne;</b></i>',
				".m_clear" :			'<i class="fa fa-lg fa-ban"></i>',
				".m_count" :			'<i class="fa fa-lg fa-plus-circle"></i>',
				".m_countReset" :		'<i class="fa fa-lg fa-plus-circle"></i>',
				".m_error" :			'<i class="fa fa-lg fa-times-circle"></i>',
				".m_info" :				'<i class="fa fa-lg fa-info-circle"></i>',
				".m_profile" :			'<i class="fa fa-lg fa-pie-chart"></i>',
				".m_profileEnd" :		'<i class="fa fa-lg fa-pie-chart"></i>',
				".m_time" :				'<i class="fa fa-lg fa-clock-o"></i>',
				".m_timeLog" :			'<i class="fa fa-lg fa-clock-o"></i>',
				".m_warn" :				'<i class="fa fa-lg fa-warning"></i>'
			},
			debugKey: getDebugKey()
		},
		listenersRegistered = false;

	/*
		Load dependencies
	*/

	addCssFa();

	loadDependencies([
		{
			src: options.jQuerySrc,
			onLoaded: init,
			check: function () {
				return typeof window.jQuery !== "undefined";
			}
		},
		{
			src: options.clipboardSrc,
			onLoaded: function () {
				/*
					Copy strings/floats/ints to clipboard when clicking
				*/
				clipboard = window.ClipboardJS;
				new clipboard('.debug .t_string, .debug .t_int, .debug .t_float, .debug .t_key', {
					target: function (trigger) {
						notify("Copied to clipboard");
						return trigger;
					}
				});
			},
			check: function() {
				return typeof window.ClipboardJS !== "undefined";
			}
		}
	]);

	function init() {
		console.info("init");
		$ = window.jQuery;
		$.fn.debugEnhance = function(method) {
			// console.warn("debugEnhance", this);
			var $self = this;
			if (typeof method == "object") {
				$.extend(options, method);
			} else if (method) {
				if (method === "addCss") {
					addCss(arguments[1]);
				} else if (method === "expand") {
					expand($self);
				} else if (method === "collapse") {
					collapse($self);
				} else if (method === "registerListeners") {
					registerListeners($self);
				} else if (method === "enhanceGroupHeader") {
					enhanceGroupHeader($self);
				}
				return;
			}
			this.each(function(){
				var $self = $(this);
				if ($self.hasClass("enhanced")) {
					// console.log("enhanced");
					return;
				}
				if ($self.hasClass("debug")) {
					console.warn("enhancing debug");
					addCss(this.selector);
					addPersistOption($self);
					addExpandAll($self);
					enhanceErrorSummary($self);
					registerListeners($self);
					// only enhance root log entries
					// enhance collapsed/hidden entries when expanded
					enhanceEntries($self.find("> .debug-header, > .debug-content"));
					$self.find(".channels").show();
					$self.find(".loading").hide();
					$self.addClass("enhanced");
				} else {
					// console.log("enhancing node");
					enhanceEntry($self);
				}
			});
			return this;
		};

		/*
		$.fn.selectText = function() {
			var node = this[0];
			console.warn('node', node);
			if (window.getSelection) {
				console.log('getSelection');
				const selection = window.getSelection();
				const range = document.createRange();
				range.selectNodeContents(node);
				selection.removeAllRanges();
				selection.addRange(range);
			} else if (document.body.createTextRange) {
				console.log('createTextRange');
				const range = document.body.createTextRange();
				range.moveToElementText(node);
				range.select();
			}
		};
		*/

		/*
		$.fn.bgColor = function() {
			var $node = $(this[0]),
				colors = [],
				bgColor;
			do {
				bgColor = colorParse($node.css("background-color"));
				if ($node.is("html")) {
					bgColor = [255,255,255,1];
				}
				colors.push(bgColor);
				if (bgColor[3] == 1) {
					break;
				}
			} while ($node = $node.parent());
			colors = colors.reverse();
			bgColor = blendColors.apply(null, colors);
			bgColor = 'rgba(' + bgColor.join(',') + ')';
			return bgColor;
		}
		*/

		$(function() {
			var $debug = $(".debug");
			if ($debug.length) {
				$debug.debugEnhance();
			} else {
				addCss();
				registerListeners($("body"));
			}
			addNoti($("body"));
		});
	} // end init

	/*
	function colorParse(color)
	{
		color = (color || "").replace(/\s+/g, '').toLowerCase();
		if (color == "transparent" || color == "") {
			color = [0,0,0,0];
		} else if (matches = color.match(/rgba\((\d+),(\d+),(\d+),(\d+)\)/)) {
			color = matches.slice(1).map(function(val){return parseFloat(val, 10)});
		} else if (matches = color.match(/rgb\((\d+),(\d+),(\d+)\)/)) {
			color = matches.slice(1).map(function(val){return parseFloat(val, 10)});
			color.push(1);
		}
		return color;
	}

	function blendColors() {
		var args = Array.prototype.slice.call(arguments),
			alpha,
			add1 = [0, 0, 0, 0],
			add2,
			mixed;
		while (add2 = args.shift()) {
			if (typeof add2[3] === 'undefined' || add2[3] == 1) {
				add2[3] = 1;
				mixed = add2;
			} else if (add1[3] && add2[3]) {
				alpha = 1 - (1 - add1[3]) * (1 - add2[3]);
				mixed = [
					// red
					Math.round(
						(add2[0] * add2[3] / alpha) +
						(add1[0] * add1[3] * (1 - add2[3]) / alpha)
					),
					// green
					Math.round(
						(add2[1] * add2[3] / alpha) +
						(add1[1] * add1[3] * (1 - add2[3]) / alpha)
					),
					// blue
					Math.round(
						(add2[2] * add2[3] / alpha) +
						(add1[2] * add1[3] * (1 - add2[3]) / alpha)
					),
					alpha
				];
			} else if (add2[3]) {
				mixed = add2;
			} else {
				mixed = add1;
			}
			add1 = mixed;
		}
		return mixed;
	}
	*/

	/**
	 * add font-awsome icons
	 */
	function addIcons($root, types) {
		if (!$.isArray(types)) {
			types = typeof types === "undefined" ?
				["misc"] :
				[types];
		}
		if ($.inArray("misc", types) >= 0) {
			$.each(options.iconsMisc, function(selector,v){
				$root.find(selector).prepend(v);
			});
		}
		if ($.inArray("object", types) >= 0) {
			$.each(options.iconsObject, function(selector,v){
				$root.find(selector).prepend(v);
			});
			$root.find("> .property > .fa:first-child, > .property > span:first-child > .fa").addClass("fa-fw");
		}
		if ($.inArray("methods", types) >= 0) {
			$.each(options.iconsMethods, function(selector,v){
				var $caption;
				if ($root.is(selector)) {
					if ($root.is("table")) {
						$caption = $root.find('caption');
						if (!$caption.length) {
							$caption = $("<caption></caption>");
							$root.prepend($caption);
						}
						$root = $caption;
					}
					$root.prepend(v);
					return false;
				}
			});
		}
	}

	function groupErrorIconChange($toggle) {
		var selector = ".fa-times-circle, .fa-warning",
			$target= $toggle.next(),
			icon = groupErrorIconGet($target),
			isExpanded = $toggle.hasClass(".expanded");
		$toggle.removeClass("empty");
		if (icon) {
			if ($toggle.find(selector).length) {
				$toggle.find(selector).replaceWith(icon);
			} else {
				$toggle.append(icon);
			}
			if (!isExpanded) {
				toggleIconChange($toggle, options.classes.expand);
			}
		} else {
			$toggle.find(selector).remove();
			if ($target.children().not(".m_warn, .m_error").length < 1) {
				// group only contains errors & they're now hidden
				$toggle.addClass("empty");
				toggleIconChange($toggle, options.classes.empty);
			}
		}
	}

	function groupErrorIconGet($container) {
		var icon = "";
		if ($container.find(".m_error").not(".hidden-error").length) {
			icon = options.iconsMethods[".m_error"];
		} else if ($container.find(".m_warn").not(".hidden-error").length) {
			icon = options.iconsMethods[".m_warn"];
		}
		return icon;
	}

	/**
	 * Collapse an array, group, or object
	 *
	 * @param jQueryObj $toggle   the toggle node
	 * @param immediate immediate no annimation
	 *
	 * @return void
	 */
	function collapse($toggle, immediate) {
		var $target = $toggle.next(),
			$groupEndValue;
		if ($toggle.is("[data-toggle=array]")) {
			// show and use the "expand it" toggle as reference toggle
			$toggle = $toggle.closest(".t_array").prev().show();
			$target = $toggle.next();
			$target.hide();
		} else {
			if ($toggle.is("[data-toggle=group]")) {
				$groupEndValue = $target.find("> .m_groupEndValue > :last-child");
				groupErrorIconChange($toggle);
				if ($groupEndValue.length) {
					$toggle.find(".group-label").last().after('<span class="t_operator"> : </span>' + $groupEndValue[0].outerHTML);
				}
			}
			$toggle.removeClass("expanded");
			if (immediate) {
				$target.hide();
				toggleIconChange($toggle, options.classes.expand);
			} else {
				$target.slideUp("fast", function() {
					toggleIconChange($toggle, options.classes.expand);
				});
			}
		}
	}

	function enhanceArrays($root) {
		// console.info("enhanceArrays", $root);
		var isDirect = $root.hasClass("t_array");
		$root.find(".t_array").each( function() {
			var $self = $(this),
				$expander = $('<span class="t_array-expand" data-toggle="array">' +
						'<span class="t_keyword">array</span><span class="t_punct">(</span> ' +
						'<i class="fa ' + options.classes.expand + '"></i>&middot;&middot;&middot; ' +
						'<span class="t_punct">)</span>' +
					"</span>"),
				$parents = $self.parentsUntil($root, ".t_object, .t_array"),
				isHidden = isDirect ?
					$parents.length > 0 :
					$parents.length > 1;
			if (isHidden || $self.hasClass("enhanced")) {
				return;
			}
			// console.info("enhancing array", $self);
			$self.addClass("enhanced");
			if ( $.trim( $self.find(".array-inner").html() ).length < 1 ) {
				// empty array -> don't add expand/collapse
				$self.find("br").hide();
				$self.find(".array-inner").hide();
				return;
			}
			// add collapse link
			$self.find(".t_keyword").first().
				wrap('<span class="t_array-collapse expanded" data-toggle="array">').
				after('<span class="t_punct">(</span> <i class="fa ' + options.classes.collapse + '"></i>').
				parent().next().remove();	// remove original "("
			if ( !isDirect && $self.parents(".t_array, .t_object").length < 1 ) {
				// outermost array -> leave open
				$self.before($expander.hide());
			} else {
				$self.hide().before($expander);
			}
		});
	}

	function enhanceErrorSummary($root) {
		// console.log("enhanceSummary");
		var $errorSummary = $root.find(".alert.error-summary");
		$errorSummary.find("h3:first-child").prepend(options.iconsMethods[".m_error"]);
		$errorSummary.find("li[class*=error-]").each( function() {
			var classAttr = $(this).attr("class"),
				html = $(this).html(),
				htmlNew = '<label>' +
					'<input type="checkbox" checked data-toggle="error" value="' + classAttr + '" /> ' +
					html +
					'</label>';
			$(this).html(htmlNew).removeAttr("class");
		});
	}

	function enhanceEntries($root) {
		// console.log("enhanceEntries", $root);
		$root.hide();
		$root.children().not(".m_group").each(function(){
			enhanceEntry($(this));
		});
		$root.show();
	}

	function enhanceEntry($root) {
		// console.log("enhanceEntry", $root);
		if ($root.hasClass("enhanced")) {
			return;
		}
		if ($root.hasClass("group-header")) {
			// minimal enhancement... just adds data-toggle attr and hides target
			// target will not be enhanced until expanded
			addIcons($root, ["methods"]);
			enhanceGroupHeader($root);
			$root.addClass("enhanced");
		} else if ($root.hasClass("m_groupSummary")) {
			// groupSummary has no toggle.. and is uncollapsed -> enhance
			enhanceEntries($root);
		} else if ($root.hasClass("m_group")) {
			/*
				group contents will get enhanced when expanded
			*/
		} else {
			// console.log("enhance", $root);
			$root.addClass("enhanced");
			enhanceArrays($root);
			enhanceObjects($root);
			enhanceStrings($root);
			enhanceTables($root);
			enhanceMisc($root);
			addIcons($root, ["misc","methods"]);
		}
	}

	function enhanceGroupHeader($toggle) {
		var $target = $toggle.next();
		// console.warn("enhanceGroupHeader", $toggle.text(), $toggle.attr("class"));
		$toggle.attr("data-toggle", "group");
		$.each(["level-error","level-info","level-warn"], function(i, val){
			var $i;
			if ($toggle.hasClass(val)) {
				$i = $toggle.children('i').eq(0);
				$toggle.wrapInner('<span class="'+val+'"></span>');
				$toggle.prepend($i); // move icon
			}
		});
		$toggle.removeClass("collapsed level-error level-info level-warn"); // collapsed class is never used
		if ($.trim($target.html()).length < 1) {
			// console.log("adding empty class");
			$toggle.addClass("empty");
			toggleIconChange($toggle, options.classes.empty);
			return;
		}
		if ($toggle.hasClass("expanded") || $target.find(".m_error, .m_warn").not(".hidden-error").length) {
			expand($toggle);
		} else {
			collapse($toggle, true);
		}
	}

	function enhanceMisc($root) {
		$root.find(".timestamp").each(function() {
			var $this = $(this),
				$i = $this.find("i"),
				text = $this.text(),
				$span = $("<span>"+text+"</span>");
			if ($this.hasClass("t_string")) {
				$span.addClass("t_string numeric");
			} else if ($this.hasClass("t_int")) {
				$span.addClass("t_int");
			} else {
				$span.addClass("t_float");
			}
			if ($this.hasClass("no-pseudo")) {
				$span.addClass("no-pseudo");
			}
			$this.removeClass("t_float t_int t_string numeric no-pseudo");
			$this.html($i).append($span);
		});
	}

	/**
	 * Adds toggle icon & hides target
	 * Minimal DOM manipulation -> apply to all descendants
	 */
	function enhanceObjects($root) {
		$root.find(".t_object > .t_classname").each( function() {
			var $toggle = $(this),
				$target = $toggle.next();
			/*
			if ($toggle.hasClass("enhanced")) {
				return;
			}
			*/
			$toggle.addClass("enhanced");
			if ($target.is(".t_recursion, .excluded")) {
				$toggle.addClass("empty");
				return;
			}
			$toggle.append(' <i class="fa ' + options.classes.expand + '"></i>');
			$toggle.attr("data-toggle", "object");
			$target.hide();
		});
	}

	function enhanceObjectInner($inner) {
		// console.info("enhanceObjectInner", $inner);
		var $wrapper = $inner.parent(),
			hasProtected = $inner.children(".protected").length > 0,
			hasPrivate = $inner.children(".private").length > 0,
			hasExcluded = $inner.children(".excluded").hide().length > 0,
			accessible = $wrapper.data("accessible"),
			toggleClass = accessible === "public" ?
				"toggle-off" :
				"toggle-on",
			toggleVerb = accessible === "public" ?
				"show" :
				"hide",
			visToggles = "",
			hiddenInterfaces = [];
		if ($inner.find(".method[data-implements]").hide().length) {
			// linkify visibility
			$inner.find(".method[data-implements]").each( function() {
				var iface = $(this).data("implements");
				if (hiddenInterfaces.indexOf(iface) < 0) {
					hiddenInterfaces.push(iface);
				}
			});
			$.each(hiddenInterfaces, function(i, iface) {
				$inner.find(".interface").each(function(){
					var html = '<span class="toggle-off" data-toggle="interface" data-interface="'+iface+'" title="toggle methods">' +
							'<i class="fa fa-eye-slash"></i> ' + iface + "</span>";
					if ($(this).text() == iface) {
						$(this).html(html);
					}
				});
			});
		}
		$wrapper.find(".private, .protected").
			filter(".magic, .magic-read, .magic-write").
			removeClass("private protected");
		if (accessible === "public") {
			$wrapper.find(".private, .protected").hide();
		}
		if (hasProtected) {
			visToggles += ' <span class="toggle-vis '+toggleClass+'" data-toggle="protected">' + toggleVerb + " protected</span>";
		}
		if (hasPrivate) {
			visToggles += ' <span class="toggle-vis '+toggleClass+'" data-toggle="private">' + toggleVerb + " private</span>";
		}
		if (hasExcluded) {
			visToggles += ' <span class="toggle-vis '+toggleClass+'" data-toggle="excluded">' + toggleVerb + " excluded</span>";
		}
		$inner.prepend('<span class="vis-toggles">' + visToggles + "</span>");
		enhanceArrays($inner);
		addIcons($inner, ["object"]);
		$inner.find("> .property.forceShow").show().find("> .t_array-expand").each(function(){
			expand($(this));
		});
	}

	function enhanceStrings($root) {
		$root.find('.t_string').each(function(){
			var text = $(this).text(),
				numLines = (text.match(/[\r\n]/g) || '').length + 1,
				$container,
				$stringWrap;
			if (numLines > 5) {
				$stringWrap = $(this).wrap('<div class="show-more-wrapper"></div>').parent();
				$stringWrap.append('<div class="show-more-fade"></div>');
				$container = $stringWrap.wrap('<div class="show-more-container"></div>').parent();
				$container.append('<button type="button" class="show-more"><i class="fa fa-caret-down"></i> More</button>');
				$container.append('<button type="button" class="show-less" style="display:none;"><i class="fa fa-caret-up"></i> Less</button>');
			}
		});
	}

	function enhanceTables($root) {
		if ($root.is("table.sortable")) {
			makeSortable($root[0]);
		}
	}

	function expand($toggleOrTarget) {
		var isToggle = $toggleOrTarget.is("[data-toggle]"),
			$toggle = isToggle
				? $toggleOrTarget
				: $(),
			$target = isToggle
				? $toggleOrTarget.next()
				: $toggleOrTarget;
		if (!isToggle) {
			enhanceEntries($target);
			return;
		}
		if (!$target.hasClass("enhanced")) {
			$target.addClass("enhanced");
			if ($toggle.is("[data-toggle=group]")) {
				// console.log("enhancing group");
				// should currently be hidden..
				enhanceEntries($target);
			} else if ($toggle.is("[data-toggle=object]")) {
				// console.log("enhancing object");
				enhanceObjectInner($target);
			} else {
				// console.log("enhancing array", $toggle);
				// enhanceArrays($target);
			}
		}
		if ($toggle.is("[data-toggle=array]")) {
			// hide the toggle..  there is a different toggle in the expanded version
			enhanceArrays($target);
			$toggle.hide();
			$target.show();
		} else {
			$target.slideDown("fast", function(){
				var $groupEndValue = $target.find("> .m_groupEndValue");
				$toggle.addClass("expanded");
				toggleIconChange($toggle, options.classes.collapse);
				if ($groupEndValue.length) {
					// remove value from label
					$toggle.find(".group-label").last().nextAll().remove();
				}
			});
		}
	}

	function toggleCollapse(toggle) {
		var $toggle = $(toggle);
		if ($toggle.hasClass("empty")) {
			return;
		}
		if ($toggle.hasClass("expanded")) {
			$toggle.debugEnhance("collapse");
		} else {
			$toggle.debugEnhance("expand");
		}
	}

	function toggleIconChange($toggle, classNameNew) {
		// console.log("toggleIconChange", $toggle.text(), classNameNew);
		var $icon = $toggle.children("i").eq(0);
		$icon.addClass(classNameNew);
		$.each(options.classes, function(i,className) {
			if (className !== classNameNew) {
				$icon.removeClass(className);
			}
		});
	}

	function toggleInterfaceVis(toggle) {
		var $toggle = $(toggle),
			iface = $(toggle).data("interface"),
			$methods = $(toggle).closest(".t_object").find("> .object-inner > dd[data-implements="+iface+"]");
		if ($(toggle).hasClass("toggle-off")) {
			$toggle.addClass("toggle-on").removeClass("toggle-off");
			$methods.show();
		} else {
			$toggle.addClass("toggle-off").removeClass("toggle-on");
			$methods.hide();
		}
	}

	function toggleObjectVis(toggle) {
		var vis = $(toggle).data("toggle"),
			$toggles = $(toggle).closest(".t_object").find(".toggle-vis[data-toggle="+vis+"]");
		if ($(toggle).hasClass("toggle-off")) {
			// show for this and all descendants
			$toggles.
				html($(toggle).html().replace("show ", "hide ")).
				addClass("toggle-on").
				removeClass("toggle-off");
			$(toggle).closest(".t_object").find(".property."+vis).show();
		} else {
			// hide for this and all descendants
			$toggles.
				html($(toggle).html().replace("hide ", "show ")).
				addClass("toggle-off").
				removeClass("toggle-on");
			$(toggle).closest(".t_object").find(".property."+vis).hide();
		}
	}

	/*
		Initialization type Functions
	*/

	/**
	 * Adds CSS to head of page
	 */
	function addCss(scope) {
		var css = "" +
				".debug .error-fatal:before { padding-left: 1.25em; }" +
				".debug .error-fatal i.fa-times-circle { position:absolute; top:.7em; }" +
				".debug .debug-cookie { color:#666; }" +
				".debug .hidden-channel, .debug .hidden-error { display:none !important; }" +
				".debug i.fa, .debug .m_assert i { margin-right:.33em; }" +
				".debug .m_assert > i { position:relative; top:-.20em; }" +
				".debug i.fa-plus-circle { opacity:0.42; }" +
				".debug i.fa-calendar { font-size:1.1em; }" +
				".debug i.fa-eye { color:#00529b; font-size:1.1em; border-bottom:0; }" +
				".debug i.fa-magic { color: orange; }" +
				".debug .excluded > i.fa-eye-slash { color:#f39; }" +
				".debug i.fa-lg { font-size:1.33em; }" +
				".debug .group-header.expanded i.fa-warning, .debug .group-header.expanded i.fa-times-circle { display:none; }" +
				".debug .group-header i.fa-warning { color:#cdcb06; margin-left:.33em}" +		// warning
				".debug .group-header i.fa-times-circle { color:#D8000C; margin-left:.33em;}" +	// error
				".debug a.expand-all { font-size:1.25em; color:inherit; text-decoration:none; display:block; clear:left; }" +
				".debug .group-header.hidden-channel + .m_group," +
				"	.debug *:not(.group-header) + .m_group {" +
				"		margin-left: 0;" +
				"		border-left: 0;" +
				"		padding-left: 0;" +
				"		display: block !important;" +
				"	}" +
				".debug [data-toggle]," +
				"	.debug [data-toggle][title]," +	// override .debug[title]
				"	.debug .vis-toggles span { cursor:pointer; }" +
				".debug .group-header.empty, .debug .t_classname.empty { cursor:auto; }" +
				".debug .vis-toggles span:hover," +
				"	.debug [data-toggle=interface]:hover { background-color:rgba(0,0,0,0.1); }" +
				".debug .vis-toggles .toggle-off," +
				"	.debug .interface .toggle-off { opacity:0.42 }" +
				".debug .show-more-container {display: inline;}" +
				".debug .show-more-wrapper {display:block; position:relative; height:5em; overflow:hidden;}" +
				".debug .show-more-fade {" +
				"	position:absolute;" +
				"	bottom:0;" +
				"	width:100%; height:4em;" +
				"	background-image: linear-gradient(to bottom, transparent, white);" +
				"	pointer-events: none;" +
				"}" +
				".debug .m_error .show-more-fade { background-image: linear-gradient(to bottom, transparent, #FFBABA); }" +
				".debug .m_info .show-more-fade { background-image: linear-gradient(to bottom, transparent, #BDE5F8); }" +
				".debug .m_warn .show-more-fade { background-image: linear-gradient(to bottom, transparent, #FEEFB3); }" +
				".debug [title]:hover .show-more-fade { background-image: linear-gradient(to bottom, transparent, #c9c9c9); }" +
				".debug .show-more, .debug .show-less {" +
				"   display: table;" +
				"	box-shadow: 1px 1px 0px 0px rgba(0,0,0, 0.20);" +
				"	border: 1px solid rgba(0,0,0, 0.20);" +
				"	border-radius: 2px;" +
				"	background-color: #EEE;" +
				"}" +
				".debug-noti-wrap {" +
				"	position: fixed;" +
				"	display: none;" +
				"	top: 0;" +
				"	width: 100%;" +
				"	height: 100%;" +
				"	pointer-events: none;" +
				"}" +
				".debug-noti-wrap .debug-noti {" +
				"	display: table-cell;" +
				"	text-align: center;" +
				"	vertical-align: bottom;" +
				"	font-size: 30px;" +
				"	transform-origin: 50% 100%;" +
				"}" +
				".debug-noti-table {display:table; width:100%; height:100%}" +
				".debug-noti.animate {" +
				"	animation-duration: 1s;" +
				"	animation-name: expandAndFade;" +
				"	animation-timing-function: ease-in;" +
				"}" +
				"@keyframes expandAndFade {" +
				"	from {" +
				"		opacity: .9;" +
				"		transform: scale(.9, .94);" +
				"	}" +
				"	to {" +
				"		opacity: 0;" +
				"		transform: scale(1, 1);" +
				"	}" +
				"}" +
				"",
			id = 'debug_javascript_style';
		if (scope) {
			css = css.replace(new RegExp(/\.debug\s/g), scope+" ");
			id += scope.replace(/\W/g, "_");
		}
		if ($("head").find("#"+id).length === 0) {
			$('<style id="' + id + '">' + css + '</style>').appendTo("head");
		}
	}

	function addNoti($root) {
		$root.append('<div class="debug-noti-wrap">' +
				'<div class="debug-noti-table">' +
					'<div class="debug-noti"></div>' +
				'</div>' +
			'</div>');
	}

	/**
	 * Add FontAwesome CSS to head of page (if missing)
	 */
	function addCssFa() {
		var i,
			links = document.head.getElementsByTagName("link"),
			count = links.length,
			haveFa = false;
		for (i = 0; i < count; i++) {
			if (links[i].outerHTML.indexOf("font-awesome") > -1) {
				haveFa = true;
				break;
			}
		}
		if (!haveFa) {
			addStylesheet(options.fontAwesomeCss);
		}
	}

	function addExpandAll($root) {
		// console.log("addExpandAll");
		var $expandAll = $("<a>", {
				"href":"#"
			}).html('<i class="fa fa-lg fa-plus"></i> Expand All Groups').addClass("expand-all");
		if ( $root.find(".group-header").length ) {
			$expandAll.on("click", function() {
				$root.find(".group-header").not(".expanded").each( function() {
					toggleCollapse(this);
				});
				return false;
			});
			$root.find(".debug-header").before($expandAll);
		}
	}

	function addPersistOption($root) {
		var $node;
		if (options.debugKey) {
			$node = $('<label class="debug-cookie"><input type="checkbox"> Keep debug on</label>').css({"float":"right"});
			if (cookieGet("debug") === options.debugKey) {
				$node.find("input").prop("checked", true);
			}
			$("input", $node).on("change", function() {
				var checked = $(this).is(":checked");
				console.log("debug persist checkbox changed", checked);
				if (checked) {
					console.log("debugKey", options.debugKey);
					cookieSave("debug", options.debugKey, 7);
				} else {
					cookieRemove("debug");
				}
			});
			$root.find(".debug-bar").eq(0).prepend($node);
		}
	}

	function addScripts(deps, checkOnly) {
		var first = document.getElementsByTagName("script")[0],
			jsNode,
			dep,
			i;
		for (i = deps.length - 1; i >= 0; i--) {
			dep = deps[i];
			if (dep.check()) {
				// dependency exists
				dep.onLoaded();
				deps.splice(i, 1);	// remove it
				continue;
			}
			if (dep.status != "loading") {
				dep.status = "loading";
				if (!checkOnly) {
					jsNode = document.createElement("script");
					jsNode.src = dep.src;
					first.parentNode.insertBefore(jsNode, first);
				}
			}
		}
	}

	function addStylesheet(src) {
		var link = document.createElement("link");
		link.type = "text/css";
		link.rel = "stylesheet";
		link.href = src;
		document.head.appendChild(link);
	}

	function getDebugKey() {
		var key = null,
			queryParams = queryDecode(),
			cookieValue = cookieGet("debug");
		if (typeof queryParams.debug !== "undefined") {
			key = queryParams.debug;
		} else if (cookieValue) {
			key = cookieValue;
		}
		return key;
	}

	function loadDependencies(deps) {
		var checkInterval,
			intervalCounter = 1;
		deps.reverse();
		if (document.getElementsByTagName("body")[0].childElementCount == 1) {
			// output only contains debug
			// don't wait for interval to begin
			addScripts(deps);
		} else {
			addScripts(deps, true);
		}
		checkInterval = setInterval(function() {
			addScripts(deps, intervalCounter === 10);
			if (deps.length === 0) {
				clearInterval(checkInterval);
			} else if (intervalCounter === 20) {
				clearInterval(checkInterval);
			}
			intervalCounter++;
		}, 500);
	}

	function registerListeners($root) {
		console.warn("registerListeners");
		$root.on("click", "[data-toggle=array]", function(){
			toggleCollapse(this);
			return false;
		});
		$root.on("click", "[data-toggle=group]", function(){
			toggleCollapse(this);
			return false;
		});
		$root.on("click", "[data-toggle=object]", function(){
			toggleCollapse(this);
			return false;
		});
		$root.on("click", ".toggle-vis", function(){
			toggleObjectVis(this);
			return false;
		});
		$root.on("click", "[data-toggle=interface]", function(){
			toggleInterfaceVis(this);
			return false;
		});
		$root.on("click", ".alert-dismissible .close", function(){
			$(this).parent().remove();
		});
		$root.on("click", ".show-more-container .show-more", function(){
			var $container = $(this).closest(".show-more-container");
			$container.find(".show-more-wrapper").animate({
				height: $container.find(".t_string").height()
			},400,"swing",function(){
				console.warn('boo', this);
				$(this).css("display", "inline");
			});
			$container.find(".show-more-fade").fadeOut();
			$container.find(".show-more").hide();
			$container.find(".show-less").show();
		});
		$root.on("click", ".show-more-container .show-less", function(){
			var $container = $(this).closest(".show-more-container");
			$container.find(".show-more-wrapper")
				.css("display", "block")
				.animate({
					height: '5em'
				});
			$container.find(".show-more-fade").fadeIn();
			$container.find(".show-more").show();
			$container.find(".show-less").hide();
		});

		$root.on("change", "input[data-toggle=channel]", function(){
			var channel = $(this).val(),
				$nodes = $(this).data("isRoot")
					? $root.find(".m_group > *").not(".m_group").filter(function(){
							var nodeChannel = $(this).data("channel");
							return  nodeChannel === channel || nodeChannel === undefined;
						})
					: $root.find('.m_group > [data-channel="'+channel+'"]').not(".m_group");
			$nodes.toggleClass("hidden-channel", !$(this).is(":checked"));
		});

		$root.on("change", "input[data-toggle=error]", function(){
			var className = $(this).val(),
				selector = ".debug-header ." + className +", .debug-content ."+className;
			$root.find(selector).toggleClass("hidden-error", !$(this).is(":checked"));
			// update icon for all groups having nested error
			// groups containing only hidden erros will loose +/-
			$root.find(".m_error, .m_warn").parents(".m_group").prev(".group-header").each(function(){
				groupErrorIconChange($(this));
			});
		});

		if (listenersRegistered) {
			return;
		}

		$("body").on("animationend", ".debug-noti", function () {
			$(this).removeClass("animate").closest(".debug-noti-wrap").hide();
		});

		listenersRegistered = true;
	}

	/*
		Utils
	*/

	function cookieGet(name) {
		var nameEQ = name + "=",
			ca = document.cookie.split(";"),
			c = null,
			i = 0;
		for ( i = 0; i < ca.length; i += 1 ) {
			c = ca[i];
			while (c.charAt(0) === " ") {
				c = c.substring(1, c.length);
			}
			if (c.indexOf(nameEQ) === 0) {
				return c.substring(nameEQ.length, c.length);
			}
		}
		return null;
	}

	function cookieRemove(name) {
		cookieSave(name, "", -1);
	}

	function cookieSave(name, value, days) {
		console.log("cookieSave", name, value, days);
		var expires = "",
			date = new Date();
		if ( days ) {
			date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
			expires = "; expires=" + date.toGMTString();
		}
		document.cookie = name + "=" + encodeURIComponent(value) + expires + "; path=/";
	}

	function queryDecode(qs) {
		var params = {},
			tokens,
			re = /[?&]?([^&=]+)=?([^&]*)/g;
		if ( qs === undefined ) {
			qs = document.location.search;
		}
		qs = qs.split("+").join(" ");	// replace + with " "
		while ( true ) {
			tokens = re.exec(qs);
			if ( !tokens ) {
				break;
			}
			params[decodeURIComponent(tokens[1])] = decodeURIComponent(tokens[2]);
		}
		return params;
	}

	/**
	 * Add sortability to given table
	 */
	function makeSortable(table) {
		var $table = $(table),
			$head = $table.find("> thead");
		$table.addClass("table-sort");
		$head.on("click", "th", function(){
			var $th = $(this),
				$cells = $(this).closest("tr").children(),
				i = $cells.index($th),
				curDir = $th.hasClass("sort-asc") ? "asc" : "desc",
				newDir = curDir === "desc" ? "asc" : "desc";
			$cells.removeClass("sort-asc sort-desc");
			$th.addClass("sort-"+newDir);
			if (!$th.find(".sort-arrows").length) {
				// this th needs the arrow markup
				$cells.find(".sort-arrows").remove();
				$th.append('<span class="fa fa-stack sort-arrows pull-right">' +
						'<i class="fa fa-caret-up" aria-hidden="true"></i>' +
						'<i class="fa fa-caret-down" aria-hidden="true"></i>' +
					"</span>");
			}
			sortTable(table, i, newDir);
		});
	}

	function notify(html) {
		$(".debug-noti").html(html).addClass("animate").closest(".debug-noti-wrap").show();
	}

	/**
	 * sort table
	 *
	 * @param obj table dom element
	 * @param int col   column index
	 * @param str dir   (asc) or desc
	 */
	function sortTable(table, col, dir) {
		var body = table.tBodies[0],
			rows = body.rows,
			i,
			collator = typeof Intl.Collator === "function"
				? new Intl.Collator([], {
					numeric: true,
					sensitivity: 'base'
				})
				: false;
		dir = dir === "desc" ? -1 : 1;
		rows = Array.prototype.slice.call(rows, 0), // Converts HTMLCollection to Array
		rows = rows.sort(function (trA, trB) {
			var a = trA.cells[col].textContent.trim(),
				b = trB.cells[col].textContent.trim();
			return collator
				? dir * collator.compare(a, b)
				: dir * a.localeCompare(b);	// not a natural sort
		});
		for (i = 0; i < rows.length; ++i) {
			body.appendChild(rows[i]); // append each row in order (which moves)
		}
	}

}( window.jQuery || undefined, window.ClipboardJS || undefined ));
