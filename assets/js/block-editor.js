/**
 * SnapBook — native block editor UI (no build step).
 *
 * Registers snapbook/booking-form using the wp.* runtime globals and
 * element.createElement. The editor shows a clean branded placeholder plus
 * an inspector panel (pre-select a package, override accent colors); the
 * live form is rendered server-side by the PHP render_callback.
 */
(function (wp) {
  "use strict";
  if (!wp || !wp.blocks || !wp.element) return;

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var registerBlockType = wp.blocks.registerBlockType;
  var blockEditor = wp.blockEditor || wp.editor || {};
  var InspectorControls = blockEditor.InspectorControls;
  var PanelColorSettings = blockEditor.PanelColorSettings;
  var useBlockProps = blockEditor.useBlockProps;
  var components = wp.components || {};
  var PanelBody = components.PanelBody;
  var SelectControl = components.SelectControl;
  var Placeholder = components.Placeholder;
  var __ = (wp.i18n && wp.i18n.__) || function (s) { return s; };

  var data = window.sbBlockData || { packages: [], i18n: {} };
  var t = data.i18n || {};
  var packages = data.packages || [];

  // Branded inline SVG icon (calendar + lens).
  var icon = el(
    "svg",
    { width: 24, height: 24, viewBox: "0 0 24 24", xmlns: "http://www.w3.org/2000/svg", "aria-hidden": "true", focusable: "false" },
    el("rect", { x: 3, y: 4, width: 18, height: 17, rx: 3, fill: "none", stroke: "currentColor", strokeWidth: 1.6 }),
    el("path", { d: "M3 9h18", stroke: "currentColor", strokeWidth: 1.6 }),
    el("path", { d: "M8 2.5v3M16 2.5v3", stroke: "currentColor", strokeWidth: 1.6, strokeLinecap: "round" }),
    el("circle", { cx: 12, cy: 15, r: 3, fill: "none", stroke: "currentColor", strokeWidth: 1.6 }),
    el("circle", { cx: 12, cy: 15, r: 1, fill: "currentColor" })
  );

  var packageOptions = [{ label: t.packageNone || __("— None —", "snapbook"), value: "" }];
  packages.forEach(function (p) {
    packageOptions.push({ label: p.label, value: p.value });
  });

  function selectedLabel(value) {
    if (!value) return "";
    for (var i = 0; i < packages.length; i++) {
      if (packages[i].value === value) return packages[i].label;
    }
    return value;
  }

  registerBlockType("snapbook/booking-form", {
    apiVersion: 2,
    title: t.title || __("SnapBook Booking Form", "snapbook"),
    description: t.description || "",
    icon: icon,
    category: "snapbook",
    keywords: ["snapbook", "booking", "appointment", "calendar", "photography"],
    supports: { align: ["wide", "full"], html: false },
    attributes: {
      package: { type: "string", default: "" },
      primaryColor: { type: "string", default: "" },
      accentColor: { type: "string", default: "" },
      align: { type: "string", default: "" },
    },

    edit: function (props) {
      var attributes = props.attributes;
      var setAttributes = props.setAttributes;
      var blockProps = useBlockProps ? useBlockProps() : {};

      var inspector = el(
        InspectorControls,
        {},
        el(
          PanelBody,
          { title: t.bookingPanel || __("Booking Form", "snapbook"), initialOpen: true },
          el(SelectControl, {
            label: t.packageLabel || __("Pre-select a package", "snapbook"),
            value: attributes.package,
            options: packageOptions,
            onChange: function (v) { setAttributes({ package: v }); },
            help: t.packageHelp || "",
            __nextHasNoMarginBottom: true,
          })
        ),
        PanelColorSettings
          ? el(
              PanelColorSettings,
              {
                title: t.colorsPanel || __("Colors", "snapbook"),
                initialOpen: false,
                colorSettings: [
                  {
                    value: attributes.primaryColor,
                    onChange: function (v) { setAttributes({ primaryColor: v || "" }); },
                    label: t.primaryColor || __("Primary color", "snapbook"),
                  },
                  {
                    value: attributes.accentColor,
                    onChange: function (v) { setAttributes({ accentColor: v || "" }); },
                    label: t.accentColor || __("Accent color", "snapbook"),
                  },
                ],
              },
              el("p", { style: { margin: "4px 0 0", fontSize: "12px", color: "#757575" } }, t.colorsHelp || "")
            )
          : null
      );

      var chosen = selectedLabel(attributes.package);
      var instructions = [];
      instructions.push(el("span", { key: "d" }, t.description || ""));
      if (chosen) {
        instructions.push(
          el(
            "span",
            { key: "p", style: { display: "block", marginTop: "8px", fontWeight: 600 } },
            (t.selectedPrefix || "Pre-selected:") + " " + chosen
          )
        );
      }
      instructions.push(
        el(
          "span",
          { key: "n", style: { display: "block", marginTop: "8px", fontStyle: "italic", opacity: 0.75 } },
          t.previewNote || ""
        )
      );

      var placeholder = el(Placeholder, {
        icon: icon,
        label: t.title || __("SnapBook Booking Form", "snapbook"),
        instructions: el(Fragment, {}, instructions),
        className: "sb-block-placeholder",
      });

      return el(Fragment, {}, inspector, el("div", blockProps, placeholder));
    },

    // Dynamic block — rendered by PHP on the front end.
    save: function () {
      return null;
    },
  });
})(window.wp);
