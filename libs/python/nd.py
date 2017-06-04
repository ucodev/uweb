#!/usr/bin/env python

#
# This file is part of uweb (http://github.com/ucodev/uweb)
# 
# uWeb ND Library - Python
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
import os
import uweb
import json
import requests

EXIT_SUCCESS = 0
EXIT_FAILURE = 1

class nd:
	limit = 500
	data_dir = 'content'

	def __init__(self, data_dir = 'content', limit = 500):
		self.data_dir = data_dir
		self.limit = limit

	def _file_contents(self, filename):
		with open(filename) as fp:
			return fp.read()

	def get(self, obj, force = False):
		# Initialize uWeb REST interface
		u = uweb.rest()

		# Retrieve object documentation
		r = u.options(obj)

		# Grant that we received somethig useful
		if r['code'] != 200:
			raise Exception("No such object: " + obj)

		# Determine which fields should be fetched that are compatible with imports
		try:
			fields = r['json']['data']['method']['POST']['request']['body']['collection']['accepted']
		except KeyError:
			# If the object does not support imports, check if we are forcing an export anyway ...
			if force == True:
				# .. if so, retrieve the exportable fields from the object collection
				try:
					fields = r['json']['data']['method']['GET']['response']['body']['collection']['visible']
				except KeyError:
					# If there are no fields defined for the object collection, we cannot export it
					raise Exception("Object does not support imports nor exports")
				except Exception as e:
					# Something exceptional ocurred
					raise Exception("Unable to export object: " + str(e))
			else:
				# ... otherwise, do not allow exports that cannot be imported
				raise Exception("Object does not support imports")
		except Exception as e:
			# Something exceptional ocurred
			raise Exception("Unable to export object: " + str(e))

		# Initialize dump
		dump = {}
		dump['controller'] = obj
		dump['entries'] = []
		dump['fields'] = fields

		# Always add 'id' field, if not already present
		if 'id' not in dump['fields']:
			dump['fields'] = [ 'id' ] + dump['fields']

		# Initialize offset
		offset = 0

		# Iterate over the object data collection
		while True:
			# Retrieve the first chunk of data
			r = u.listing(obj, self.limit, offset)

			# Grant that we received something useful
			if r['code'] != 200 or not r['json']['data']['count']:
				break;

			# Iterate over each row from the result
			for row in r['json']['data']['result']:
				# (Re)Initialize entry
				entry = []

				# Filter any fields that cannot be imported
				for field in dump['fields']:
					if field[0:6] == '_file_':
						# Retrieve file contents
						try:
							fr = requests.get(row[field]['url'])
						except Exception as e:
							raise Exception("Unable to retrieve file '" + row[field]['url'] + "': " + str(e))

						# Craft entry subfolder name
						local_entry_dir = self.data_dir.rstrip('/') + '/' + str(row['id']) + '/' + field

						# Create subfolder for entry, if it doesn't exist
						if os.path.exists(local_entry_dir):
							# If the path exists, grant that it's a directory
							if not os.path.isdir(local_entry_dir):
								raise Exception(local_entry_dir + "isn't a directory")
						else:
							try:
								os.makedirs(local_entry_dir)
							except Exception as e:
								raise Exception("Unable to create directory '" + local_entry_dir + "': " + str(e))

						# Craft local filename
						local_filename = local_entry_dir + '/' + row[field]['name']

						# Store file contents into the specified directory
						try:
							with open(local_filename, "w+") as fp:
								fp.write(fr.content)
						except Exception as e:
							raise Exception("Unable to write file to '" + local_filename + "': " + str(e))

						# Assign local filename as the actual field value
						value = local_filename
					else:
						value = row[field]

					# Append value to entry value list
					entry.append(value)

				# Add this entry to the dump entries collection
				dump['entries'].append(entry)

			# Increment offset to fetch the next chunk of data
			offset += self.limit

		# All good
		return dump
			
	def put(self, obj, dump, fmt = 'json'):
		# Currently, only JSON encoded documents can be imported
		if fmt != 'json':
			raise Exception("Only JSON encoded data can be imported. Set fmt parameter to 'json'.")

		# Initialize uWeb REST interface
		u = uweb.rest()

		# Retrieve object documentation
		r = u.options(obj)

		# Grant that we received somethig useful
		if r['code'] != 200:
			raise Exception("Failed to retrieve documentation for object: " + obj)

		# Determine which fields should be fetched that are compatible with imports
		try:
			insert_fields = r['json']['data']['method']['POST']['request']['body']['collection']['accepted']
		except KeyError:
			raise Exception("Object " + obj + " does not support data inserts.")

		# Retrieve the last ID from object collection
		try:
			r = u.listing(obj, 1, 0, 'id', 'desc')

			# Check if there are any results
			if r['json']['data']['count']:
				id_last = r['json']['data']['result'][0]['id']
			else:
				id_last = 0
		except Exception as e:
			raise Exception("Unable to retrieve last inserted ID from the object: " + str(e))

		# CHeck if there's anything to be imported
		if dump['entries'][-1][0] >= id_last:
			return None

		# Iterate over dump entries and import them
		for entry in dump['entries']:
			# Ignore IDs below the last inserted ID
			if entry[0] < id_last:
				continue

			# Initialize payload for this entry
			payload = { }

			# Craft request payload
			for index,value in enumerate(entry):
				# Do not include the IDs in the payload
				if dump['fields'][index] == 'id':
					continue

				# Check if the field is POSTeable
				if dump['fields'][index] not in insert_fields:
					continue

				# Assign value to the current field
				payload[dump['fields'][index]] = value

			# Try to insert the entry
			try:
				r = u.insert(obj, json.dumps(payload))
			except Exception as e:
				raise Exception("Unable to insert entry id " + entry[0] + ": " + str(e))

			# Check if the entry was successfully inserted
			if r['code'] != 201:
				raise Exception("Failed to insert entry id " + entry[0] + ": Status code: " + r['code'])

		# All good
		return True

	def patch(self, obj, dump, fmt = 'json'):
		# Currently, only JSON encoded documents can be imported
		if fmt != 'json':
			raise Exception("Only JSON encoded data can be used for updates. Set fmt parameter to 'json'.")

		# Initialize uWeb REST interface
		u = uweb.rest()

		# Retrieve object documentation
		r = u.options(obj)

		# Grant that we received somethig useful
		if r['code'] != 200:
			raise Exception("No such object: " + obj)

		# Determine which fields should be fetched that are compatible with imports
		try:
			update_fields = r['json']['data']['method']['PATCH']['request']['body']['single']['accepted']
		except KeyError:
			raise Exception("Object " + obj + " does not support data updates.")

		# Iterate over dump entries and import them
		for entry in dump['entries']:
			# Initialize payload for this entry
			payload = { }

			# Craft request payload
			for index,value in enumerate(entry):
				# Do not include the IDs in the payload
				if dump['fields'][index] == 'id':
					continue

				# Check if the field is PATCHeable
				if dump['fields'][index] not in update_fields:
					continue

				# Assign value to the current field
				payload[dump['fields'][index]] = value

			# Try to insert the entry
			try:
				r = u.modify(obj, entry[0], json_data = json.dumps(payload))
			except Exception as e:
				raise Exception("Unable to update entry id " + entry[0] + ": " + str(e))

			# Check if the entry was successfully inserted
			if r['code'] != 200:
				raise Exception("Failed to update entry id " + entry[0] + ": Status code: " + r['code'])

		# All good
		return True

	def load(self, obj, fmt = 'json'):
		return json.loads(self._file_contents(self.data_dir + '/' + obj + '.' + fmt))

	def save(self, obj, dump, fmt = 'json', csv_delim = ',', csv_quote = '\"', csv_crlf = '\n'):
		if fmt == 'json':
			# Encode data to JSON
			data = json.dumps(dump, indent = 4, sort_keys = False)
		elif fmt == 'csv':
			# Encode data to CSV

			# (Re)Initialize dump
			data = ""

			# Dump header columns (column titles)
			for header in dump['fields']:
				data += csv_quote + header + csv_quote + csv_delim

			# Strip trailing delimiters and append newline
			data = self.dump.strip(csv_delim)
			data += csv_crlf

			# Iterate over dump entries
			for row in dump['entries']:
				# Iterate over each value, quoting and applying delimiters
				for v in row:
					data += csv_quote + (str(v) if v != None else "") + csv_quote + csv_delim

				# Strip trailing delimiters and append newline
				data = self.dump.strip(csv_delim)
				data += csv_crlf
		else:
			raise Exception("Unsupported export format: " + fmt + ". Supported formats are: json, csv")

		# Craft dump filename
		dump_filename = self.data_dir + '/' + obj + '.' + fmt

		# Try to dump exported data into object file
		try:
			# Dump data
			with open(dump_filename, 'w+') as fp:
				fp.write(data)
		except Exception as e:
			raise Exception("Cannot create file: " + dump_filename + ": " + str(e))

		# All good
		return data


if __name__ == "__main__":
	if len(sys.argv) < 5:
		print("Usage: %s <import|export|update> <object> <directory> <format> <force>" % sys.argv[0])
		sys.exit(EXIT_FAILURE)

	# Process command-line arguments
	op = sys.argv[1]
	obj = sys.argv[2]
	data_dir = sys.argv[3]

	# Validate format
	fmt = sys.argv[4]

	if fmt != 'json' and fmt != 'csv':
		print("Argument 'format' must be 'json' or 'csv'")
		sys.exit(EXIT_FAILURE)

	# Check if 'force' was used
	if len(sys.argv) >= 6:
		force = sys.argv[5].lower()

		if force == 'true':
			force = True
		elif force == 'false':
			force = False
		else:
			print("Argument 'force' must be set to 'true' or 'false'")
			sys.exit(EXIT_FAILURE)
	else:
		# Default value for 'force' is False
		force = False

	# Initialize ND interface
	n = nd(data_dir)

	# Process operation
	if op == "export":
		# Try to fetch data
		try:
			dump = n.get(obj, force)
		except Exception as e:
			print("Failed to retrieve data: " + str(e))
			sys.exit(EXIT_FAILURE)

		# Try to save data to filesystem
		try:
			n.save(obj, dump, fmt)
		except Exception as e:
			print("Failed to save data: " + str(e))
			sys.exit(EXIT_FAILURE)
	elif op == 'import':
		# Try to load data
		try:
			dump = n.load(obj, fmt)
		except Exception as e:
			print("Failed to load data: " + str(e))
			sys.exit(EXIT_FAILURE)

		# Try to import data
		try:
			status = n.put(obj, dump, fmt)
		except Exception as e:
			print("Failed to import data: " + str(e))
			sys.exit(EXIT_FAILURE)

		# Check import status
		if status == None:
			print("All data already imported.")
			sys.exit(EXIT_SUCCESS)
		elif status == True:
			print("All data successfully imported.")
			sys.exit(EXIT_SUCCESS)
		else:
			print("Unknown result.")
			sys.exit(EXIT_FAILURE)
	elif op == 'update':
		# Try to load data
		try:
			dump = n.load(obj, fmt)
		except Exception as e:
			print("Failed to load data: " + str(e))
			sys.exit(EXIT_FAILURE)

		# Try to import data
		try:
			status = n.patch(obj, dump, fmt)
		except Exception as e:
			print("Failed to update data: " + str(e))
			sys.exit(EXIT_FAILURE)

		# Check import status
		if status == True:
			print("All data successfully updated.")
			sys.exit(EXIT_SUCCESS)
		else:
			print("Unknown result.")
			sys.exit(EXIT_FAILURE)
	else:
		print("Unknown operation: " + op)
		sys.exit(EXIT_FAILURE)

	# All good
	print("Completed.")
	sys.exit(EXIT_SUCCESS)

