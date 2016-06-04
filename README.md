# Titan
A simple PHP ORM like Rails ActiveRecord

Titan connects classes to relational database tables.
Titan relies heavily on naming conventions for classes and tables to establish mappings between and object and his table, primary keys and foreign keys. Although it supports manual configurations, it's recommended to follow naming conventions.

Features:
------------

 - Automated mapping between classes and tables, attributes and columns.
 - CRUD abstraction in form of methods.
 - Generated methods for finding records by every column in the corresponding table of the object.
 - Generated methods for creating and filling the foreign key of an object that is related to another object.
 - File attaching (uploading/deletion) for an attribute of an object.
 - Associations between objects defined by class attributes.
	 - Has one
	 - Has many
	 - Belongs to
	 - Has many through
	
