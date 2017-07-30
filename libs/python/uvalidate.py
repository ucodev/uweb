#!/usr/bin/env python

#
# This file is part of uweb (http://github.com/ucodev/uweb)
#
# uWeb Model Validation Library - Python
# Copyright (C) 2015-2017  Pedro A. Hortas (pah@ucodev.org)
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

ATC_RESET = "\x1b[0m"
ATC_BOLD = "\x1b[1m"
ATC_UNDERLINE = "\x1b[4m"
ATC_GREEN = "\x1b[32m"
ATC_YELLOW = "\x1b[33m"
ATC_RED = "\x1b[31m"
ATC_CYAN = "\x1b[36m"

class uvalidate:
	count_failed = 0
	count_passed = 0
	count_total = 0

	def __init__(self):
		# Initialize uweb interface
		self.u = uweb.rest()

	def log(self, msg):
		sys.stdout.write(msg)
		sys.stdout.flush()

	def passed(self):
		self.log(" " + ATC_BOLD + "-> " + ATC_GREEN + "PASSED" + ATC_RESET + ATC_BOLD + " <-" + ATC_RESET + "\n")
		self.count_passed += 1

	def failed(self):
		self.log(" " + ATC_BOLD + "-> " + ATC_RED + "FAILED" + ATC_RESET + ATC_BOLD + " <-" + ATC_RESET + "\n")
		self.count_failed += 1

	def get_full_fieldset(self):
		fieldset = []

		for method in self.r_options['json']['data']['method']:
			for context in ('request', 'response'):
				# Fetch fields from all method contexts
				if 'body' not in self.r_options['json']['data']['method'][method][context]:
					continue

				for function in self.r_options['json']['data']['method'][method][context]['body']:
					for scope in self.r_options['json']['data']['method'][method][context]['body'][function]:
						for field in self.r_options['json']['data']['method'][method][context]['body'][function][scope]:
							fieldset.append(field)

		# Reduce list to set
		return set(fieldset)

	def check_fields_type_in_desc(self):
		self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "fTypeInDesc" + ATC_RESET + "]: ")

		# Grant field types are present in descriptions
		for field in self.r_options['json']['data']['fields']['types']:
			if field not in self.r_options['json']['data']['fields']['descriptions'].keys():
				self.log(ATC_RED + "Failed" + ATC_RESET + ": Field '" + field + "' not present in field descriptions")
				return False

		self.log(ATC_GREEN + "OK" + ATC_RESET)

		# All good
		return True

	def check_fields_desc_in_type(self):
		self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "fDescInType" + ATC_RESET + "]: ")

		# Grant that fields set in descriptions are present in types
		for field in self.r_options['json']['data']['fields']['descriptions']:
			if field not in self.r_options['json']['data']['fields']['types'].keys():
				self.log(ATC_RED + "Failed" + ATC_RESET + ": Description for field '" + field + "' not present in field types")
				return False

		self.log(ATC_GREEN + "OK" + ATC_RESET)

		# ALl good
		return True

	def check_fields_default_in_type(self):
		self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "fDefaultInType" + ATC_RESET + "]: ")

		# Grant that fields set in defaults are present in types
		for field in self.r_options['json']['data']['fields']['defaults']:
			if field not in self.r_options['json']['data']['fields']['types'].keys():
				self.log(ATC_RED + "Failed" + ATC_RESET + ": Default value set for field '" + field + "' but not present in field types")
				return False

		self.log(ATC_GREEN + "OK" + ATC_RESET)

		# All good
		return True

	def check_fields_option_in_type(self):
		self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "fOptionInType" + ATC_RESET + "]: ")

		# Grant that fields set in option values are present in types
		for field in self.r_options['json']['data']['fields']['options']:
			if field not in self.r_options['json']['data']['fields']['types'].keys():
				self.log(ATC_RED + "Failed" + ATC_RESET + ": Optional values defined for field '" + field + "' but not present in field types")
				return False

		self.log(ATC_GREEN + "OK" + ATC_RESET)

		# All good
		return True

	def check_fields_fieldset_in_type(self):
		self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "fSetInType" + ATC_RESET + "]: ")

		# Get all available fields from all possible request/response body
		fieldset = self.get_full_fieldset()

		# Check if all request/response body fields are set in field types
		for field in fieldset:
			if field not in self.r_options['json']['data']['fields']['types'].keys():
				self.log(ATC_RED + "Failed" + ATC_RESET + ": Field '" + field + "' not present in field types")
				return False

		self.log(ATC_GREEN + "OK" + ATC_RESET)

		# All good
		return True

	def check_fields_type_in_fieldset(self):
		self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "fTypeInSet" + ATC_RESET + "]: ")

		# Get all available fields from all possible request/response body
		fieldset = self.get_full_fieldset()

		# Check if all fields set in field types are used
		for field in self.r_options['json']['data']['fields']['types'].keys():
			if field not in fieldset:
				self.log(ATC_RED + "Failed" + ATC_RESET + ": Field '" + field + "' defined in field types is unused")
				return False

		self.log(ATC_GREEN + "OK" + ATC_RESET)

		# All good
		return True

	def validate_model(self):
		# OPTIONS

		# Grant field types are present in descriptions
		if self.check_fields_type_in_desc() != True:
			self.failed()
			return False

		# Grant field types are present in descriptions
		if self.check_fields_desc_in_type() != True:
			self.failed()
			return False

		# Grant that fields set in defaults are present in types
		if self.check_fields_default_in_type() != True:
			self.failed()
			return False

		# Grant that fields set in option values are present in types
		if self.check_fields_option_in_type() != True:
			self.failed()
			return False

		# Check if all request/response body fields are set in field types
		if self.check_fields_fieldset_in_type() != True:
			self.failed()
			return False
		
		# Check if all fields set in field types are used
		if self.check_fields_type_in_fieldset() != True:
			self.failed()
			return False

		# All checks passed
		self.passed()

		# All good
		return True

	def load(self, obj_name):
		self.log(ATC_BOLD + " -> " + ATC_CYAN + obj_name + ATC_RESET + ATC_BOLD + " <- " + ATC_RESET)

		# OPTIONS
		self.log("[" + ATC_UNDERLINE + "options" + ATC_RESET + "]: ")

		# Retrieve object model
		self.r_options = self.u.options(obj_name)

		# Grant that we successfully retrieved object model
		if self.r_options['json']['info']['code'] != 200 or self.r_options['json']['info']['errors']:
			self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(self.r_options['json']['info']['code']) + "]: " + self.r_options['json']['errors']['message'] + " ")
			return False

		# Grant that there's data to work with
		if self.r_options['json']['info']['data'] == True:
			self.log(ATC_GREEN + "OK" + ATC_RESET + " [" + str(self.r_options['json']['info']['code']) + "]")

		# Set current object name
		self.obj_name = obj_name

		# All good
		return True

	def obj(self, obj_name):
		# Load the object
		load_status = self.load(obj_name)

		# Grant that we can use the object
		if load_status != True:
			self.log("Unable to load object: " + obj_name + "\n")

		# Run model validation
		if self.validate_model() != True:
			return False

		# All good
		return True

	def run(self):
		# Fetch API options
		r = self.u.options('/')

		data = r['json']['data']

		# Count the total number of objects available
		self.count_total = len(data['objects'])

		# Iterate over each object and validate it
		for obj_name in data['objects']:
			self.obj(obj_name)

	def summary(self):
		# Summary
		if self.count_total != (self.count_passed + self.count_failed):
			self.log("\n " + ATC_BOLD + "-> " + ATC_YELLOW + "WARNING" + ATC_RESET + ": Not all objects were validated (%d validated objects out of %d total objects)\n" % (self.count_passed + self.count_failed, self.count_total))

		self.log("\n")

		self.log("Total      : " + ATC_BOLD +              ("%d" % (self.count_total)) + ATC_RESET + "\t(100%)\n")
		self.log("           -\n")
		self.log("Passed     : " + ATC_BOLD + ATC_GREEN  + ("%d" % (self.count_passed))     + ATC_RESET + ("\t(%.2f%%)\n" % (self.count_passed * 100.0 / self.count_total)))
		self.log("Failed     : " + ATC_BOLD + ATC_RED    + ("%d" % (self.count_failed))     + ATC_RESET + ("\t(%.2f%%)\n" % (self.count_failed * 100.0 / self.count_total)))

		self.log("\n")

	def status(self):
		return (False if self.count_failed > 0 else True)

	def do(self):
		# Run validations
		self.run()

		# Summary
		self.summary()

		# Return status
		return self.status()


if __name__ == '__main__':
	# Initialize uweb validation interface
	v = uvalidate()

	# Run validations
	sys.exit(EXIT_SUCCESS if v.do() == True else EXIT_FAILURE)

