#!/usr/bin/env python

#
# This file is part of uweb (http://github.com/ucodev/uweb)
# 
# uWeb RESTful Self-Documentation Library - Python
# Copyright (C) 2014-2017  Pedro A. Hortas (pah@ucodev.org)
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
#


import sys
import uweb

EXIT_SUCCESS = 0
EXIT_FAILURE = 1

class udoc:
	def __init__(self, store_dir = './', fmt = 'rst'):
		if fmt not in ('rst'):
			raise Exception("Unsupported output format: " + fmt)

		self.fmt = fmt
		self.store_dir = store_dir

	def get_method_list(self, doc_struct):
		method_list = []

		for method in doc_struct['method']:
			method_list.append(method)

		return set(method_list)

	def has_method_function(self, doc_struct, method, function):
		return method in doc_struct['method'] and function in doc_struct['method'][method]['request']['uri']

	def get_function_list(self, doc_struct, exclude_list = [ 'collection', 'single', 'search' ]):
		function_list = []

		for method in doc_struct['method']:
			for function in doc_struct['method'][method]['request']['uri']:
				if function in exclude_list:
					continue

				function_list.append(function)

		return set(function_list)

	def get_function_methods(self, doc_struct, function):
		function_methods = []

		for method in doc_struct['method']:
			if function in doc_struct['method'][method]['request']['uri']:
				function_methods.append(method)

		return set(function_methods)

	def dump(self, doc_struct, endpoint, description, url):
		# Initialize doc output
		d = udoc_rst(doc_struct, endpoint, description, store_dir = self.store_dir)

		# Title - Endpoint
		d.title()

		# Description
		d.endpoint_desc()

		# TOC
		d.toc()

		# Section - URL
		d.section("Base URL")
		d.code_begin()
		d.writeline(url)
		d.code_end()

		# Section - Fields
		d.section("Fields")

		# Subsection - Fields - Description
		d.subsection("Description")
		d.uweb_field_descriptions(doc_struct['fields']['descriptions'])

		# Subsection - Fields - Types
		d.subsection("Types")
		d.uweb_field_types(doc_struct['fields']['types'])

		# Subsection - Fields - Options
		if len(doc_struct['fields']['options']):
			d.subsection("Options")
			d.uweb_field_options(doc_struct['fields']['options'])

		# Subsection - Fields - Defaults
		if len(doc_struct['fields']['defaults']):
			d.subsection("Default values")
			d.uweb_field_defaults(doc_struct['fields']['defaults'])

		# Section - Operations - Common
		d.section("RESTful Operations - Common")

		for method in self.get_method_list(doc_struct):
			if method == 'GET':
				if not self.has_method_function(doc_struct, method, 'single'):
					continue

				d.subsection("View entry")
				d.paragraph("Retrieves the contents of the specified entry ``id``:")
				d.uweb_function(method, 'single')

				if not self.has_method_function(doc_struct, method, 'collection'):
					continue
			
				d.subsection("List collection")
				d.paragraph("Lists the collection contents:")
				d.uweb_function(method, 'collection')
			elif method == 'POST':
				if not self.has_method_function(doc_struct, method, 'collection'):
					continue

				d.subsection("Create entry")
				d.paragraph("Creates a new collection entry:")
				d.uweb_function(method, 'collection')
			elif method == 'PATCH':
				if not self.has_method_function(doc_struct, method, 'single'):
					continue

				d.subsection("Modify entry")
				d.paragraph("Modifies part or the full contents of the specified entry ``id``:")
				d.uweb_function(method, 'single')
			elif method == 'PUT':
				if not self.has_method_function(doc_struct, method, 'single'):
					continue

				d.subsection("Update entry")
				d.paragraph("Updates the full contents of the specified entry ``id``:")
				d.uweb_function(method, 'single')
			elif method == 'DELETE':
				if not self.has_method_function(doc_struct, method, 'single'):
					continue

				d.subsection("Delete entry")
				d.paragraph("Deletes the specified entry ``id``:")
				d.uweb_function(method, 'single')

		# Section - Operations - Search
		d.section("RESTful Operations - Search")

		if self.has_method_function(doc_struct, 'POST', 'search'):
			d.subsection("Search collection")
			d.paragraph("Performs a NDSL query over the entry collection:")
			d.uweb_function('POST', 'search', entry_repr = False)

		# Section - Operations - Custom
		custom_functions = self.get_function_list(doc_struct)

		if custom_functions:
			d.section("RESTful Operations - Custom")

			for function in custom_functions:
				for method in self.get_function_methods(doc_struct, function):
					d.subsection("Custom - " + function)

					# Use the first note as function description, if available
					if 'notes' in doc_struct['method'][method]['request'] and function in doc_struct['method'][method]['request']['notes']:
						d.paragraph(doc_struct['method'][method]['request']['notes'][function][0])
					else:
						d.paragraph("<no description>")

					d.uweb_function(method, function, entry_repr = False)

	def crawl(self):
		u = uweb.rest()

		r = u.options('/')

		base_struct = r['json']['data']

		for obj in base_struct['objects']:
			r = u.options(obj)

			doc_struct = r['json']['data']

			self.dump(doc_struct, obj, base_struct['objects'][obj]['description'], base_struct['objects'][obj]['uri'])


class udoc_rst:
	note_count = 1
	http_code_description = {
		# 2xx codes ...
		'200': 'OK',
		'201': 'Created',
		'202': 'Accepted',
		'204': 'No Content',
		# 3xx codes ...
		'304': 'Not Modified',
		# 4xx codes ...
		'400': 'Bad Request',
		'401': 'Unauthorized',
		'403': 'Forbidden',
		'404': 'Not Found',
		'405': 'Method Not Allowed',
		'406': 'Not Acceptable',
		'409': 'Conflict',
		'410': 'Gone',
		'412': 'Precondition Failed',
		# 5xx codes ...
		'500': 'Internal Server Error',
		'502': 'Bad Gateway',
		'503': 'Service Unavailable'
	}

	def __init__(self, uweb_doc_struct, endpoint, description = None, store_dir = './'):
		self.uweb_doc_struct = uweb_doc_struct
		self.endpoint = endpoint
		self.description = description
		self.f = open(store_dir.strip('/') + '/' + endpoint + '.rst', 'w+')

	def writeline(self, t, newline = True):
		self.f.write(t + ("\n" if newline else ""))

	def title(self):
		self.writeline("*" * len(self.endpoint))
		self.writeline(self.endpoint)
		self.writeline("*" * len(self.endpoint))
		self.newline()

	def endpoint_desc(self):
		if self.description:
			self.writeline(self.description)
			self.writeline("")

	def toc(self):
		self.writeline(".. contents::")
		self.newline()

	def section(self, section):
		self.writeline(section)
		self.writeline("=" * len(section))
		self.newline()

	def subsection(self, subsection):
		self.writeline(subsection)
		self.writeline("-" * len(subsection))
		self.newline()

	def subsubsection(self, subsubsection):
		self.writeline(subsubsection)
		self.writeline("^" * len(subsubsection))
		self.newline()

	def paragraph(self, paragraph):
		self.writeline(paragraph)
		self.newline()

	def newline(self):
		self.writeline("")

	def bullet(self, bullet_text):
		self.writeline("* " + bullet_text)

	def note(self, note):
		self.writeline(".. [" + str(self.note_count) + "] " + note)
		self.note_count += 1
		self.newline()

	def code_begin(self, syntax = None):
		self.writeline(".. code::" + ((" " + syntax) if syntax else ""))
		self.newline()

	def code_end(self):
		self.newline()

	def table_kv(self, title_key, title_value, kv, kv_alt = None, k2str = True, v2str = True, v2concat = False):
		if not kv_alt:
			kv_alt = kv

		# Reset max len for key and value
		max_len_key   = len(title_key)
		max_len_value = len(title_value)

		# Compute maximum len of field description key and value
		for k in kv:
			if k2str:
				k = str(k)

			if v2concat and isinstance(kv_alt[k], list):
				v = ", ".join(map(lambda x: str(x), kv_alt[k]))
			else:
				v = kv_alt[k]

			if v2str:
				v = str(v)

			# Compute key length and determine if it's greater than current max
			if len(v) > max_len_value:
				max_len_value = len(v)
			# Compute value length and determine if it's greater than current max
			if len(k) > max_len_key:
				max_len_key = len(k)

		# Print table head
		self.writeline("\t" + "+-" + ("-" * max_len_key) + "-+-" + ("-" * max_len_value) + "-+")
		self.writeline("\t" + "| " + (title_key + (" " * (max_len_key - len(title_key)))) + " | " + (title_value + (" " * (max_len_value - len(title_value)))) + " |")
		self.writeline("\t" + "+=" + ("=" * max_len_key) + "=+=" + ("=" * max_len_value) + "=+")

		# Print table body
		for k in kv:
			if k2str:
				k = str(k)

			if v2concat and isinstance(kv_alt[k], list):
				v = ", ".join(map(lambda x: str(x), kv_alt[k]))
			else:
				v = kv_alt[k]

			if v2str:
				v = str(v)

			# Print column
			self.writeline("\t" + "| " + (k + (" " * (max_len_key - len(k)))) + " | " + (v + (" " * (max_len_value - len(v)))) + " |")
			# Print row separator
			self.writeline("\t" + "+-" + ("-" * max_len_key) + "-+-" + ("-" * max_len_value) + "-+")

		self.newline()

	def uweb_field_descriptions(self, field_desc):
		self.table_kv("Field", "Description", field_desc)

	def uweb_field_types(self, field_types):
		self.table_kv("Field", "Type", field_types)

	def uweb_field_options(self, field_options, v2concat = True):
		self.table_kv("Field", "Options", field_options)

	def uweb_field_defaults(self, field_defaults):
		self.table_kv("Field", "Default value", field_defaults)

	def uweb_status_codes(self, status_codes = []):
		self.table_kv("Code", "Description", status_codes, self.http_code_description)

	def uweb_headers(self, headers):
		kv_hdr = { }
		for d in map(lambda x: { x.split(':')[0].strip(): ':'.join(x.split(':')[1:]).strip()}, headers):
			kv_hdr.update(d)

		self.table_kv("Key", "Value", kv_hdr)

	def uweb_field_value_repr(self, field):
		# Create value type representation
		value = "< ... undefined ... >"
		if self.uweb_doc_struct['fields']['types'][field][0:6] == "string":
			value = "\"<string>\""
		elif self.uweb_doc_struct['fields']['types'][field][0:6] == "object":
			value = "{ ... }"
		elif self.uweb_doc_struct['fields']['types'][field][0:7] == "integer":
			value = "<integer>"
		elif self.uweb_doc_struct['fields']['types'][field][0:5] == "float":
			value = "<float>"
		elif self.uweb_doc_struct['fields']['types'][field][0:7] == "boolean":
			value = "<true|false>"
		elif self.uweb_doc_struct['fields']['types'][field][0:8] == "datetime":
			value = "\"1970-01-01T00:00:00+00:00\""
		elif self.uweb_doc_struct['fields']['types'][field][0:5] == "array":
			value = "[ ... ]"
		elif self.uweb_doc_struct['fields']['types'][field][0:4] == "time":
			value = "\"00:00:00\""
		elif self.uweb_doc_struct['fields']['types'][field][0:4] == "date":
			value = "\"1970-01-01\""

		return value

	def uweb_body(self, method, context, function, params_scope, description, entry_repr = True):
		if params_scope in self.uweb_doc_struct['method'][method][context]['body'][function]:
			# Descriptive table
			self.paragraph(description)

			# Field table description
			field_value_repr = {}
			for field in self.uweb_doc_struct['method'][method][context]['body'][function][params_scope]:
				field_value_repr[field] = self.uweb_field_value_repr(field)

			self.table_kv("Field", "Value / Type", self.uweb_doc_struct['method'][method][context]['body'][function][params_scope], field_value_repr)

			# Entry level representation
			if entry_repr == True:
				self.paragraph("Entry level meta representation:")
				self.code_begin('json')
				self.writeline("\t{")
				first = True
				for field in self.uweb_doc_struct['method'][method][context]['body'][function][params_scope]:
					if first:
						first = False
					else:
						self.writeline(",\n", newline = False)

					# Create value type representation
					value = self.uweb_field_value_repr(field)

					self.writeline("\t\t\"" + field + "\": " + value, newline = False)

				self.writeline("\n\t}")
				self.code_end()

	def uweb_function(self, method, function, entry_repr = True):
		# Usage
		self.code_begin()
		self.writeline("\t" + method + ' ' + self.uweb_doc_struct['method'][method]['request']['uri'][function])
		self.code_end()

		# Request headers
		if 'headers' in self.uweb_doc_struct['method'][method]['request'] and function in self.uweb_doc_struct['method'][method]['request']['headers']:
			self.subsubsection("Request headers")
			self.uweb_headers(self.uweb_doc_struct['method'][method]['request']['headers'][function])

		# Request body
		if 'body' in self.uweb_doc_struct['method'][method]['request'] and function in self.uweb_doc_struct['method'][method]['request']['body']:
			self.subsubsection("Request body")

			self.uweb_body(method, 'request', function, 'required', "The following fields must be present in the request body:", entry_repr = entry_repr)
			self.uweb_body(method, 'request', function, 'accepted', "Any of the following fields are accepted in the request body:", entry_repr = entry_repr)

		# Request notes
		if 'notes' in self.uweb_doc_struct['method'][method]['request'] and function in self.uweb_doc_struct['method'][method]['request']['notes']:
			self.subsubsection("Request notes")
			for note in self.uweb_doc_struct['method'][method]['request']['notes'][function]:
				self.bullet(note)
			self.newline()


		# Response headers
		if 'headers' in self.uweb_doc_struct['method'][method]['response'] and function in self.uweb_doc_struct['method'][method]['response']['headers']:
			self.subsubsection("Response headers")
			self.uweb_headers(self.uweb_doc_struct['method'][method]['response']['headers'][function])

		# Response body
		if 'body' in self.uweb_doc_struct['method'][method]['response'] and function in self.uweb_doc_struct['method'][method]['response']['body']:
			self.subsubsection("Response body")

			self.uweb_body(method, 'response', function, 'visible', "Any of the following fields may be visible in the response body:", entry_repr = entry_repr)

		# Response codes
		if 'codes' in self.uweb_doc_struct['method'][method]['response'] and function in self.uweb_doc_struct['method'][method]['response']['codes']:
			# Success status codes
			if 'success' in self.uweb_doc_struct['method'][method]['response']['codes'][function]:
				self.subsubsection("Response status codes - Success")
				self.uweb_status_codes(self.uweb_doc_struct['method'][method]['response']['codes'][function]['success'])

			# Failure status codes
			if 'failure' in self.uweb_doc_struct['method'][method]['response']['codes'][function]:
				self.subsubsection("Response status codes - Failure")
				self.uweb_status_codes(self.uweb_doc_struct['method'][method]['response']['codes'][function]['failure'])

		# Response types
		if 'types' in self.uweb_doc_struct['method'][method]['response'] and function in self.uweb_doc_struct['method'][method]['response']['types']:
			self.subsubsection("Response data structure")
			self.code_begin()
			self.writeline("\t" + self.uweb_doc_struct['method'][method]['response']['types'][function])
			self.code_end()

		# Response notes
		if 'notes' in self.uweb_doc_struct['method'][method]['response'] and function in self.uweb_doc_struct['method'][method]['response']['notes']:
			self.subsubsection("Response notes")
			for note in self.uweb_doc_struct['method'][method]['response']['notes'][function]:
				self.bullet(note)
			self.newline()


if __name__ == "__main__":
	if len(sys.argv) != 3:
		print("Usage: %s <target dir> <format>")
		sys.exit(EXIT_FAILURE)

	try:
		u = udoc(store_dir = sys.argv[1], fmt = sys.argv[2])
		u.crawl()
	except Exception as e:
		print("Failed: " + str(e))
		sys.exit(EXIT_FAILURE)

	sys.exit(EXIT_SUCCESS)

