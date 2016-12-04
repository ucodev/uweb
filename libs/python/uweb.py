#!/usr/bin/env python

#
# This file is part of uweb (http://github.com/ucodev/uweb)
# 
# uWeb RESTful Library - Python
# Copyright (C) 2014-2016  Pedro A. Hortas (pah@ucodev.org)
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

import os
import json
import requests
import time
import datetime
import base64

class rest:
	auth = None
	config = None

	file_config = "config.json"
	file_authcache = ".authcache"

	# Required headers
	req_headers = {
		"Accept": "application/json",
		"Content-Type": "application/json"
	}

	# Dump file in base64 format
	def file_b64contents(self, filename):
		with open(filename) as f:
			return base64.b64encode(f.read())

	# Loads the REST API configuration
	def config_load(self):
		with open(self.file_config, "r") as f:
			self.config = json.loads(f.read())

	# Load authentication information, if any
	def auth_load(self):
		# If there's no authentication cache, return None
		if not os.path.isfile(self.file_authcache):
			return None

		# Otherwise, load the stored authentication values
		with open(self.file_authcache, "r") as f:
			# Return authentication data
			self.auth = json.loads(f.read())['data']

			# Set the authentication headers
			self.req_headers[self.config['header_user_id']] = str(self.auth['userid'])
			self.req_headers[self.config['header_auth_token']] = str(self.auth['token'])

	def request(self, method, obj, req_data = None, args = None):
		# Craft URI
		if args != None:
			if type(args) == str:
				uri = '/' + obj + '/' + args
			elif type(args) == list:
				uri = '/' + obj + '/' + '/'.join(args)
			else:
				raise Exception("Invalid type for 'args': Only str and list types are acceptable.")
		else:
			uri = '/' + obj

		# Store the start time
		t0 = time.time()

		# Peform the request
		if method == 'POST':
			r = requests.post(self.config['base_url'] + uri, data = json.dumps(req_data), headers = self.req_headers)

			# If unauthorized, login and try again
			if r.status_code == 401 and self.auto_login:
				self.login()
				r = requests.post(self.config['base_url'] + uri, data = json.dumps(req_data), headers = self.req_headers)
		elif method == 'GET':
			r = requests.get(self.config['base_url'] + uri, headers = self.req_headers)

			# If unauthorized, login and try again
			if r.status_code == 401 and self.auto_login:
				self.login()
				r = requests.get(self.config['base_url'] + uri, headers = self.req_headers)
		elif method == 'PUT':
			r = requests.put(self.config['base_url'] + uri, data = json.dumps(req_data), headers = self.req_headers)

			# If unauthorized, login and try again
			if r.status_code == 401 and self.auto_login:
				r = requests.put(self.config['base_url'] + uri, data = json.dumps(req_data), headers = self.req_headers)
		elif method == 'PATCH':
			r = requests.patch(self.config['base_url'] + uri, data = json.dumps(req_data), headers = self.req_headers)

			# If unauthorized, login and try again
			if r.status_code == 401 and self.auto_login:
				self.login()
				r = requests.patch(self.config['base_url'] + uri, data = json.dumps(req_data), headers = self.req_headers)
		elif method == 'DELETE':
			r = requests.delete(self.config['base_url'] + uri, headers = self.req_headers)

			# If unauthorized, login and try again
			if r.status_code == 401 and self.auto_login:
				self.login()
				r = requests.delete(self.config['base_url'] + uri, headers = self.req_headers)
		elif method == 'OPTIONS':
			r = requests.options(self.config['base_url'] + uri, headers = self.req_headers)

			# If unauthorized, login and try again
			if r.status_code == 401 and self.auto_login:
				self.login()
				r = requests.options(self.config['base_url'] + uri, headers = self.req_headers)
		else:
			raise Exception("Unrecognized method: " + method)

		# Store the end time
		tend = time.time()

		# Craft the return value
		ret = {}
		ret['code'] = r.status_code
		ret['json'] = r.json()
		ret['time'] = tend - t0

		# All good
		return ret

	
	# Authentication: Sign-in
	def login(self, username = None, password = None):
		req_data = {}

		if username and password:
			req_data['username'] = username
			req_data['password'] = password
		else:
			req_data['username'] = self.config['username']
			req_data['password'] = self.config['password']

		# Send the authentication request, measuring the request time
		r = self.request('POST', 'auth', req_data)

		# 201 stands for success
		if r['code'] == 201:
			# Unlink any previous authentication cache
			if os.path.isfile(self.file_authcache):
				os.unlink(self.file_authcache)

			# Create a new authentication cache file
			with open(self.file_authcache, "w") as f:
				f.write(json.dumps(r['json']))

			# Load authentication cache
			self.auth_load()
		else:
			raise Exception("Authentication failed.")

		# All good
		return True

	def session_status(self):
		r = self.request('GET', 'auth')

		return r['code'] == 200
			
	# Authentication: Sign-out
	def logout(self):
		r = self.request('DELETE', 'auth')

		if r['code'] != 200:
			raise Exception("Unable to logout")

		# Remove authentication cache
		os.unlink(self.file_authcache)
		self.auth = None

		# All good
		return True

	def signup(self, req_data):
		# Username is mandatory
		if 'username' not in req_data:
			raise Exception("Missing required property: username")

		# Password is mandatory
		if 'password' not in req_data:
			raise Exception("Missing required property: password")

		# Email is mandatory
		if 'email' not in req_data:
			raise Exception("Missing required property: email")

		return self.request('POST', 'register', req_data)

	# Class contruct
	def __init__(self, file_config = "config.json", file_authcache = ".authcache", auto_login = True):
		self.auto_login = auto_login

		# Set required file names
		self.file_config = file_config
		self.file_authcache = file_authcache

		# Load REST API configuration
		self.config_load()

		# Load any authentication cache
		self.auth_load()

		# If there's no authentication cache, perform a login
		if not self.auth and self.auto_login:
			r = self.login()

		# Check if the session is still valid
		if self.auto_login and (int(time.time()) - os.stat(file_authcache).st_mtime) >= self.config['session_lifetime'] and not self.session_status():
			r = self.login()


	## METHODS ##

	# VIEW: Fetch a specific entry id from obj
	def view(self, obj, entry_id):
		return self.request('GET', obj, args = str(entry_id))

	# LISTING: Fetch the obj collection
	def listing(self, obj, limit = 10, offset = 0, order_field = 'id', ordering = 'asc'):
		return self.request('GET', obj, args = map(lambda x: str(x), [ limit, offset, order_field, ordering ]))

	# INSERT: Inserts an entry into obj collection
	def insert(self, obj, json_data):
		req_data = json.loads(json_data)
		data = {}

		# Pre-process fields, if required...
		for item in req_data:
			# Files have a special treatment
			if "_file_" in item:
				st = os.stat(req_data[item])

				data[item] = {
					"name": req_data[item].split('/')[-1],
					"type": req_data[item].split('.')[-1].upper(),
					"created": datetime.datetime.fromtimestamp(st.st_ctime).strftime("%Y-%m-%d %H:%M:%S"),
					"modified": datetime.datetime.fromtimestamp(st.st_mtime).strftime("%Y-%m-%d %H:%M:%S"),
					"encoding": "base64",
					"contents": self.file_b64contents(req_data[item])
				}
			else:
				data[item] = req_data[item]

		return self.request('POST', obj, data)

	# MODIFY: Modifies an object property
	def modify(self, obj, entry_id, k, v):
		req_data = {}

		# Files have a special treatment
		if "_file_" in k:
			st = os.stat(v)

			req_data[k] = {
				"name": v.split('/')[-1],
				"type": v.split('.')[-1].upper(),
				"created": datetime.datetime.fromtimestamp(st.st_ctime).strftime("%Y-%m-%d %H:%M:%S"),
				"modified": datetime.datetime.fromtimestamp(st.st_mtime).strftime("%Y-%m-%d %H:%M:%S"),
				"encoding": "base64",
				"contents": self.file_b64contents(v)
			}
		else:
			req_data[k] = v

		return self.request('PATCH', obj, data = req_data, args = str(entry_id))

	# DELETE: Deletes an entry from obj collection
	def delete(self, obj, entry_id):
		return self.request('DELETE', obj, args = str(entry_id))

	# OPTIONS: Retrieve obj information
	def options(self, obj):
		return self.request('OPTIONS', obj)

