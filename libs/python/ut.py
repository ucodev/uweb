#!/usr/bin/env python

#
# This file is part of uweb (http://github.com/ucodev/uweb)
# 
# uWeb Unit Testing Library - Python
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


class ut:
	count_acceptable = 0
	count_failed = 0
	count_passed = 0
	count_total = 0

	force_acceptable = False
	force_passed = False
	force_failed = False

	def __init__(self, ignore_list = [], expect_list = {}):
		self.obj_list_ignore = ignore_list
		self.obj_list_expect = expect_list
		# Initialize uweb interface
		self.u = uweb.rest()

	def log(self, msg):
		sys.stdout.write(msg)
		sys.stdout.flush()

	def acceptable(self):
		self.log(" " + ATC_BOLD + "-> " + ATC_YELLOW + "ACCEPTABLE" + ATC_RESET + ATC_BOLD + " <-" + ATC_RESET + "\n")
		self.count_acceptable += 1

	def passed(self):
		self.log(" " + ATC_BOLD + "-> " + ATC_GREEN + "PASSED" + ATC_RESET + ATC_BOLD + " <-" + ATC_RESET + "\n")
		self.count_passed += 1

	def failed(self):
		self.log(" " + ATC_BOLD + "-> " + ATC_RED + "FAILED" + ATC_RESET + ATC_BOLD + " <-" + ATC_RESET + "\n")
		self.count_failed += 1

	def unit_listing(self):
		# LISTING

		# Grant that a collection is available for GET method
		if self.r_options['json']['data']['method'].has_key('GET') and self.r_options['json']['data']['method']['GET']['response']['body'].has_key('collection'):
			self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "listing" + ATC_RESET + "]: ")

			# Retrieve a few elements from the collection
			r_list = self.u.listing(self.obj_name, 10, 0, 'id', 'desc', False)

			# Check if the received status code is outside of the expected success codes
			if (r_list['json']['info']['code'] not in self.r_options['json']['data']['method']['GET']['response']['codes']['collection']['success']) or r_list['json']['info']['errors']:
				# Check if this status code was set as expected for this object
				if self.obj_list_expect.has_key(self.obj_name) and self.obj_list_expect[self.obj_name].has_key('listing') and (r_list['json']['info']['code'] in self.obj_list_expect[self.obj_name]['listing']['codes']):
					self.log(ATC_YELLOW + "Expected" + ATC_RESET + " [" + str(r_list['json']['info']['code']) + "]: " + r_list['json']['errors']['message'])
					self.force_acceptable = True
					return True
				else:
					self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(r_list['json']['info']['code']) + "]: " + r_list['json']['errors']['message'])
					self.failed()
					return False

			# Check if the collection is empty
			if r_list['json']['info']['data'] == True and r_list['json']['data']['count'] == 0:
				self.log(ATC_GREEN + "OK" + ATC_RESET + " [" + str(r_list['json']['info']['code']) + "] (Empty Set)")
				self.acceptable()
				return False
			else:
				self.log(ATC_GREEN + "OK" + ATC_RESET + " [" + str(r_list['json']['info']['code']) + "]")

			# Set pivot
			self.id_pivot = r_list['json']['data']['result'][0]['id']

		# All good
		return True

	def unit_view(self):
		# VIEW

		# Grant that a single element can be retrieve with GET method
		if self.r_options['json']['data']['method'].has_key('GET') and self.r_options['json']['data']['method']['GET']['response']['body'].has_key('single'):
			self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "view" + ATC_RESET + "]: ")

			# If there's no pivot set, we shall ignore this test
			if not self.id_pivot:
				self.log(ATC_YELLOW + "Ignored" + ATC_RESET + " (No pivot)")
				return True

			# Retrieve the pivot element
			r_view = self.u.view(self.obj_name, self.id_pivot)

			# Check if the received status code is outside of the expected success codes
			if (r_view['json']['info']['code'] not in self.r_options['json']['data']['method']['GET']['response']['codes']['single']['success']) or r_view['json']['info']['errors']:
				# Check if this status code was set as expected for this object
				if self.obj_list_expect.has_key(self.obj_name) and self.obj_list_expect[self.obj_name].has_key('view') and (r_view['json']['info']['code'] in self.obj_list_expect[self.obj_name]['view']['codes']):
					self.log(ATC_YELLOW + "Expected" + ATC_RESET + " [" + str(r_view['json']['info']['code']) + "]: " + r_view['json']['errors']['message'])
					self.force_acceptable = True
					return True
				else:
					self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(r_view['json']['info']['code']) + "]: " + r_view['json']['errors']['message'])
					self.failed()
					return False

			self.log(ATC_GREEN + "OK" + ATC_RESET + " [" + str(r_view['json']['info']['code']) + "]")
		else:
			# If no view method is defined, discard pivot checking for the following calls
			self.id_pivot = None

		# All good
		return True

	def unit_search(self):
		# SEARCH
		if self.r_options['json']['data']['method'].has_key('POST') and self.r_options['json']['data']['method']['POST']['response']['body'].has_key('search'):
			self.log(" " + ATC_BOLD + "->" + ATC_RESET + " [" + ATC_UNDERLINE + "search" + ATC_RESET + "]: ")

			# Craft search query based on the pivot availability
			if not self.id_pivot:
				# If no pivot is set, request one element with id greater than 0
				r_search = self.u.request('POST', self.obj_name, '{ "limit": 1, "offset": 0, "orderby": "id", "ordering": "asc", "show": [ "id" ], "query": { "id": { "gt": 0 } } }', args = 'search')
			else:
				# If pivot is set, request the element that matches the pivot id
				r_search = self.u.request('POST', self.obj_name, '{ "limit": 10, "offset": 0, "orderby": "id", "ordering": "asc", "show": [ "id" ], "query": { "id": { "eq": ' + str(self.id_pivot) + '} } }', args = 'search')

			# Check if the received status code is outside of the expected success codes
			if (r_search['json']['info']['code'] not in self.r_options['json']['data']['method']['POST']['response']['codes']['search']['success']) or r_search['json']['info']['errors']:
				# Check if this status code was set as expected for this object
				if self.obj_list_expect.has_key(self.obj_name) and self.obj_list_expect[self.obj_name].has_key('search') and (r_search['json']['info']['code'] in self.obj_list_expect[self.obj_name]['search']['codes']):
					self.log(ATC_YELLOW + "Expected" + ATC_RESET + " [" + str(r_search['json']['info']['code']) + "]: " + r_search['json']['errors']['message'])
					self.force_acceptable = True
					return True
				else:
					self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(r_search['json']['info']['code']) + "]: " + r_search['json']['errors']['message'])
					self.failed()
					return False

			# Grant that we received data
			if r_search['json']['info']['data'] == False or r_search['json']['data']['count'] < 1:
				self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(r_search['json']['info']['code']) + "] (Empty result for ID: " + str(r_list['json']['data']['result'][0]['id']) + ")")
				self.failed()
				return False

			# Grant that no more than one element is received (only one element was requested)
			if r_search['json']['info']['data'] == True and r_search['json']['data']['count'] > 1:
				self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(r_search['json']['info']['code']) + "] (More than one result for ID: " + str(self.id_pivot) + ")")
				self.failed()
				return False

			# Grant that the received element matches the id of the pivot
			if self.id_pivot and (r_search['json']['data']['result'][0]['id'] != self.id_pivot):
				self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(r_search['json']['info']['code']) + "] (ID mismatch: " + str(self.id_pivot) + "{list} vs. " + str(r_search['json']['data']['result'][0]['id']) + "{search})")
				self.failed()
				return False

			self.log(ATC_GREEN + "OK" + ATC_RESET + " [" + str(r_search['json']['info']['code']) + "]")

		# All good
		return True

	def load(self, obj_name):
		# Reset all forced status
		self.force_acceptable = False
		self.force_passed = False
		self.force_failed = False

		self.log(ATC_BOLD + " -> " + ATC_CYAN + obj_name + ATC_RESET + ATC_BOLD + " <- " + ATC_RESET)

		# Check if this object should be ignored
		if obj_name in self.obj_list_ignore:
			self.log("Ignored")
			self.acceptable()
			return None

		# OPTIONS
		self.log("[" + ATC_UNDERLINE + "options" + ATC_RESET + "]: ")

		# Retrieve object model
		self.r_options = self.u.options(obj_name)

		# Grant that we successfully retrieved object model
		if self.r_options['json']['info']['code'] != 200 or self.r_options['json']['info']['errors']:
			self.log(ATC_RED + "Failed" + ATC_RESET + " [" + str(self.r_options['json']['info']['code']) + "]: " + self.r_options['json']['errors']['message'] + " ")
			self.failed()
			return False

		# Grant that there's data to work with
		if self.r_options['json']['info']['data'] == True:
			self.log(ATC_GREEN + "OK" + ATC_RESET + " [" + str(self.r_options['json']['info']['code']) + "]")

		# Reset pivot
		self.id_pivot = None

		# Set current object name
		self.obj_name = obj_name

		# All good
		return True

	def obj(self, obj_name):
		# Load the object
		load_status = self.load(obj_name)

		# Grant that we can use the object
		if load_status != True:
			return load_status

		# Run listing tests
		if self.unit_listing() != True:
			return False

		# RUn view tests
		if self.unit_view() != True:
			return False

		# Run search tests
		if self.unit_search() != True:
			return False

		# Evaluate status
		if self.force_failed:
			self.failed()
			return False
		elif self.force_acceptable:
			self.acceptable()
		elif self.force_passed:
			self.passed()
		else:
			self.passed()

		# All good
		return True

	def run(self):
		# Fetch API options
		r = self.u.options('/')

		data = r['json']['data']

		# Count the total number of objects available
		self.count_total = len(data['objects'])

		# Iterate over each object and test it
		for obj_name in data['objects']:
			self.obj(obj_name)

	def summary(self):
		# Summary
		if self.count_total != (self.count_acceptable + self.count_passed + self.count_failed):
			self.log("\n " + ATC_BOLD + "-> " + ATC_YELLOW + "WARNING" + ATC_RESET + ": Not all objects were tested (%d tested objects out of %d total objects)\n" % (self.count_acceptable + self.count_passed + self.count_failed, self.count_total))

		self.log("\n")

		self.log("Total      : " + ATC_BOLD +              ("%d" % (self.count_total)) + ATC_RESET + "\t(100%%)\n")
		self.log("           -\n")
		self.log("Passed     : " + ATC_BOLD + ATC_GREEN  + ("%d" % (self.count_passed))     + ATC_RESET + ("\t(%.2f%%)\n" % (self.count_passed * 100.0 / self.count_total)))
		self.log("Acceptable : " + ATC_BOLD + ATC_YELLOW + ("%d" % (self.count_acceptable)) + ATC_RESET + ("\t(%.2f%%)\n" % (self.count_acceptable * 100.0 / self.count_total)))
		self.log("Failed     : " + ATC_BOLD + ATC_RED    + ("%d" % (self.count_failed))     + ATC_RESET + ("\t(%.2f%%)\n" % (self.count_failed * 100.0 / self.count_total)))

		self.log("\n")

	def status(self):
		return (False if self.count_failed > 0 else True)


	def do(self):
		# Run tests
		self.run()

		# Summary
		self.summary()

		# Status
		return self.status()


if __name__ == '__main__':
	# Initialize uweb unit test interface
	t = ut()

	# Run tests
	sys.exit(EXIT_SUCCESS if t.do() == True else EXIT_FAILURE)

