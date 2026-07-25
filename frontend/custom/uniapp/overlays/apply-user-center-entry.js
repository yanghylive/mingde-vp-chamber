'use strict';

var fs = require('fs');
var path = require('path');

var TEMPLATE_ANCHOR = '    ></PageDesign>\n';
var TEMPLATE_INSERT = '    <chamber-member-entry></chamber-member-entry>\n';
var IMPORT_ANCHOR = 'import PageDesign from "@/subpackage/diyComponents/pageDesign.vue";\n';
var IMPORT_INSERT = 'import ChamberMemberEntry from "@/components/chamberMemberEntry/index.vue";\n';
var COMPONENT_ANCHOR = '    PageDesign,\n';
var COMPONENT_INSERT = '    ChamberMemberEntry,\n';

function occurrences(source, token) {
  return source.split(token).length - 1;
}

function insertOnce(source, anchor, insertion, label) {
  if (source.indexOf(insertion) >= 0) return source;
  if (occurrences(source, anchor) !== 1) {
    throw new Error('CRMEB user center ' + label + ' marker changed');
  }
  return source.replace(anchor, anchor + insertion);
}

function applySource(source) {
  var output = String(source);
  output = insertOnce(output, TEMPLATE_ANCHOR, TEMPLATE_INSERT, 'template');
  output = insertOnce(output, IMPORT_ANCHOR, IMPORT_INSERT, 'import');
  output = insertOnce(output, COMPONENT_ANCHOR, COMPONENT_INSERT, 'component');

  [TEMPLATE_INSERT, IMPORT_INSERT, COMPONENT_INSERT].forEach(function (token) {
    if (occurrences(output, token) !== 1) throw new Error('Chamber user center entry must appear exactly once');
  });
  return output;
}

function applyToWorkspace(workspaceRoot) {
  var target = path.join(workspaceRoot, 'pages/user/index.vue');
  var source = fs.readFileSync(target, 'utf8');
  fs.writeFileSync(target, applySource(source));
}

if (require.main === module) {
  if (!process.argv[2]) throw new Error('usage: node apply-user-center-entry.js <uniapp-workspace>');
  applyToWorkspace(path.resolve(process.argv[2]));
}

module.exports = {
  applySource: applySource,
  applyToWorkspace: applyToWorkspace,
};
