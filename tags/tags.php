<?php
/**
 * @package     Joomla.Libraries
 * @subpackage  Tags
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

/**
 * Tags helper class, provides methods to perform various tasks relevant
 * tagging of content.
 *
 * @package     Joomla.Libraries
 * @subpackage  Tags
 * @since       3.1
 */
class JTags
{
	/**
	 * Method to add or update tags associated with an item. Generally used as a postSaveHook.
	 *
	 * @param   integer          $id        The id (primary key) of the item to be tagged.
	 * @param   string           $prefix    Dot separated string with the option and view for a url.
	 * @param   array            $tags      Array of tags to be applied.
	 * @param   array            $fieldMap  Associative array of values to core_content field.
	 * @param   array            $isNew     Flag indicating this item is new.
	 * @param   JControllerForm  $item      A JControllerForm object usually from a Post Save Hook
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function tagItem($id, $prefix, $isNew, $item, $tags = null, $fieldMap = null)
	{
		$db = JFactory::getDbo();

		// Set up the field mapping array
		if (empty($fieldMap))
		{
			$typeId = $this->getTypeId($prefix);
			$contenttype = JTable::getInstance('Contenttype');
			$contenttype->load($typeId);
			$map = json_decode($contenttype->field_mappings, true);

			foreach ($map['common'][0] as $i => $field)
			{
				if ($field && $field != 'null')
				{
					$fieldMap[$i] = $item->$field;
				}
			}
		}

		$types = $this->getTypes('objectList', $prefix, true);
		$type = $types[0];

		$typeid = $type->type_id;

		if ($id == 0)
		{
			$queryid = $db->getQuery(true);

			$queryid->select($db->qn('id'))
				->from($db->qn($type->table))
				->where($db->qn('type_alias') . ' = ' . $db->q($prefix));
			$db->setQuery($queryid);
			$id = $db->loadResult();
		}

		if ($isNew == 0)
		{
			// Delete the old tag maps.
			$query = $db->getQuery(true);
			$query->delete();
			$query->from($db->quoteName('#__contentitem_tag_map'));
			$query->where($db->quoteName('type_alias') . ' = ' . $db->quote($prefix));
			$query->where($db->quoteName('content_item_id') . ' = ' . (int) $id);
			$db->setQuery($query);
			$db->execute();
		}

		// Set the new tag maps.
		if (!empty($tags))
		{
			// First we fill in the core_content table.
			$querycc = $db->getQuery(true);

			// Check if the record is already there in a content table if it is not a new item.
			// It could be old but never tagged.
			if ($isNew == 0)
			{
				$querycheck = $db->getQuery(true);
				$querycheck->select($db->qn('core_content_id'))
					->from($db->qn('#__core_content'))
					->where(
						array(
							$db->qn('core_content_item_id') . ' = ' . $id,
							$db->qn('core_type_alias') . ' = ' . $db->q($prefix)
						)
				);
				$db->setQuery($querycheck);

				$ccId = $db->loadResult();
			}

			// For new items we need to get the id from the actual table.
			// Throw an exception if there is no matching record
			if ($id == 0)
			{
				$queryid = $db->getQuery(true);
				$queryid->select($db->qn('id'));
				$queryid->from($db->qn($type->table));
				$queryid->where($db->qn($map['core_alias']) . ' = ' . $db->q($fieldMap['core_alias']));
				$db->setQuery($queryid);
				$id = $db->loadResult();
				$fieldMap['core_content_item_id'] = $id;
			}

			// If there is no record in #__core_content we do an insert. Otherwise an update.
			if ($isNew == 1 || empty($ccId))
			{
				$quotedValues = array();
				foreach ($fieldMap as $value)
				{
					$quotedValues[] = $db->q($value);
				}

				$values = implode(',', $quotedValues);
				$values = $values . ',' . (int) $typeid . ', ' . $db->q($prefix);

				$querycc->insert($db->quoteName('#__core_content'))
					->columns($db->quoteName(array_keys($fieldMap)))
					->columns($db->qn('core_type_id'))
					->columns($db->qn('core_type_alias'))
					->values($values);
			}
			else
			{
				$setList = '';
				foreach ($fieldMap as $fieldname => $value)
				{
					$setList .= $db->qn($fieldname) . ' = ' . $db->q($value) . ',';
				}

				$setList = $setList . ' ' . $db->qn('core_type_id') . ' = ' . $typeid . ',' . $db->qn('core_type_alias') . ' = ' . $db->q($prefix);

				$querycc->update($db->qn('#__core_content'));
				$querycc->where($db->qn('core_content_item_id') . ' = ' . $id);
				$querycc->where($db->qn('core_type_alias') . ' = ' . $db->q($prefix));
				$querycc->set($setList);
			}

			$db->setQuery($querycc);
			$db->execute();

			// Get the core_core_content_id from the new record if we do not have it.
			if (empty($ccId))
			{
				$queryCcid = $db->getQuery(true);
				$queryCcid->select($db->qn('core_content_id'));
				$queryCcid->from($db->qn('#__core_content'));
				$queryCcid->where($db->qn('core_content_item_id') . ' = ' . $id);
				$queryCcid->where($db->qn('core_type_alias') . ' = ' . $db->q($prefix));

				$db->setQuery($queryCcid);
				$ccId = $db->loadResult();
			}

			// Have to break this up into individual queries for cross-database support.
			foreach ($tags as $tag)
			{
				$query2 = $db->getQuery(true);
				$query2->insert('#__contentitem_tag_map');
				$query2->columns(array($db->quoteName('type_alias'), $db->quoteName('content_item_id'), $db->quoteName('tag_id'), $db->quoteName('tag_date'), $db->quoteName('core_content_id')));
				$query2->clear('values');
				$query2->values($db->q($prefix) . ', ' . (int) $id . ', ' . $db->q($tag) . ', ' . $query2->currentTimestamp() . ', ' . (int) $ccId);
				$db->setQuery($query2);
				$db->execute();
			}
		}

		return;
	}

	/**
	 * Method to add tags associated to a list of items. Generally used for batch processing.
	 *
	 * @param   array    $tag       Tag to be applied. Note that his method handles single tags only.
	 * @param   integer  $ids       The id (primary key) of the items to be tagged.
	 * @param   string   $contexts  Dot separated string with the option and view for a url.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function tagItems($tag, $ids, $contexts)
	{
		// Method is not ready for use
		return;

		// Check whether the tag is present already.
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->delete();
		$query->from($db->quoteName('#__contentitem_tag_map'));
		$query->where($db->quoteName('type_alias') . ' = ' . $db->quote($prefix));
		$query->where($db->quoteName('content_item_id') . ' = ' . (int) $pk);
		$query->where($db->quoteName('tag_id') . ' = ' . (int) $tag);
		$db->setQuery($query);
		$result = $db->loadResult();
		$query->execute();

		self::tagItem($id, $prefix, $tags, $isNew, null);
		$query2 = $db->getQuery(true);

		$query2->insert($db->quoteName('#__contentitem_tag_map'));
		$query2->columns(array($db->quoteName('type_alias'), $db->quoteName('content_item_id'), $db->quoteName('tag_id'), $db->quoteName('tag_date')));

		$query2->clear('values');
		$query2->values($db->quote($prefix) . ', ' . (int) $pk . ', ' . $tag . ', ' . $query->currentTimestamp());
		$db->setQuery($query2);
		$db->execute();
	}

	/**
	 * Method to remove  tags associated with a list of items. Generally used for batch processing.
	 *
	 * @param   integer  $id      The id (primary key) of the item to be untagged.
	 * @param   string   $prefix  Dot separated string with the option and view for a url.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function unTagItem($id, $prefix)
	{
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->delete('#__contentitem_tag_map');
		$query->where($db->quoteName('type_alias') . ' = ' . $db->quote($prefix));
		$query->where($db->quoteName('content_item_id') . ' = ' . (int) $id);
		$db->setQuery($query);
		$db->execute();

		return;
	}

	/**
	 * Method to get a list of tags for a given item.
	 * Normally used for displaying a list of tags within a layout
	 *
	 * @param   integer  $id      The id (primary key) of the item to be tagged.
	 * @param   string   $prefix  Dot separated string with the option and view to be used for a url.
	 *
	 * @return  string   Comma separated list of tag Ids.
	 *
	 * @since   3.1
	 */
	public function getTagIds($id, $prefix)
	{
		if (!empty($id))
		{
			if (is_array($id))
			{
				$id = implode(',', $id);
			}

			$db = JFactory::getDbo();
			$query = $db->getQuery(true);

			// Load the tags.
			$query->clear();
			$query->select($db->quoteName('t.id'));

			$query->from($db->quoteName('#__tags') . ' AS t ');
			$query->join('INNER', $db->quoteName('#__contentitem_tag_map') . ' AS m' .
				' ON ' . $db->quoteName('m.tag_id') . ' = ' . $db->quoteName('t.id') . ' AND ' .
						$db->quoteName('m.type_alias') . ' = ' .
						$db->quote($prefix) . ' AND ' . $db->quoteName('m.content_item_id') . ' IN ( ' . $id . ')');

			$db->setQuery($query);

			// Add the tags to the content data.
			$tagsList = $db->loadColumn();
			$this->tags = implode(',', $tagsList);
		}
		else
		{
			// $this->tags = '';
		}

		return $this->tags;
	}

	/**
	 * Method to get a list of tags for an item, optionally with the tag data.
	 *
	 * @param   integer  $contentType  Name of an item. Dot separated.
	 * @param   integer  $id           Item ID
	 * @param   boolean  $getTagData   If true, data from the tags table will be included, defaults to true.
	 *
	 * @return  array    Array of of tag objects
	 *
	 * @since   3.1
	 */
	public function getItemTags($contentType, $id, $getTagData = true)
	{
		if (is_array($id))
		{
			$id = implode($id);
		}
		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->select(array($db->quoteName('m.tag_id'), $db->quoteName('t') . '.*'));
		$query->from($db->quoteName('#__contentitem_tag_map') . ' AS m ');
		$query->where(
			array(
				$db->quoteName('m.type_alias') . ' = ' . $db->quote($contentType),
				$db->quoteName('m.content_item_id') . ' = ' . $db->quote($id),
				$db->quoteName('t.published') . ' = 1'
			)
		);

		if ($getTagData)
		{
			$query->join('INNER', $db->quoteName('#__tags') . ' AS t ' . ' ON ' . $db->quoteName('m.tag_id') . ' = ' . $db->quoteName('t.id'));
		}

		$db->setQuery($query);
		$this->itemTags = $db->loadObjectList();

		return $this->itemTags;
	}

	/**
	 * Method to get a list of items for a tag.
	 *
	 * @param   integer  $tag_id       ID of the item
	 * @param   boolean  $getItemData  If true, data from the item tables will be included, defaults to true.
	 *
	 * @return  array  Array of of tag objects
	 *
	 * @since   3.1
	 */
	public function getTagItems($tag_id = null, $getItemData = true)
	{
		if (empty($tag_id))
		{
			$app = JFactory::getApplication('site');

			// Load state from the request.
			$tag_id = $app->input->getInt('id');
		}

		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->select($db->quoteName('type_alias'), $db->quoteName('id'));
		$query->from($db->quoteName('#__contentitem_tag_map'));
		$query->where($db->quoteName('tag_id') . ' = ' . (int) $tag_id);

		$db->setQuery($query);
		$this->tagItems = $db->loadObjectList();

		if ($getItemData)
		{
			foreach ($this->tagItems as $item)
			{
				$item_id = $item->content_item_id;
				$table = $item->getTableName($item->type_alias);

				$query2 = $db->getQuery(true);
				$query2->clear();

				$query2->select('*');
				$query2->from($db->quoteName($table));
				$query2->where($db->quoteName('id') . ' = ' . (int) $item_id);

				$db->setQuery($query2);
				$item->itemData = $db->loadAssoc();
			}
		}

		return $this->tagItems;
	}

	/**
	 * Returns content name from a tag map record as an array
	 *
	 * @param   string  $typeAlias  The tag item name to explode.
	 *
	 * @return  array   The exploded type alias. If name doe not exist an empty array is returned.
	 *
	 * @since   3.1
	 */
	public function explodeTypeAlias($typeAlias)
	{
		return $explodedTypeAlias = explode('.', $typeAlias);
	}

	/**
	 * Returns the component for a tag map record
	 *
	 * @param   string  $typeAlias          The tag item name.
	 * @param   array   $explodedTypeAlias  Exploded alias if it exists
	 *
	 * @return  string  The content type title for the item.
	 *
	 * @since   3.1
	 */
	public function getTypeName($typeAlias, $explodedTypeAlias = null)
	{
		if (!isset($explodedTypeAlias))
		{
			$this->explodedTypeAlias = $this->explodeTypeAlias($typeAlias);
		}

		return $this->explodedTypeAlias[0];
	}

	/**
	 * Returns the url segment for a tag map record.
	 *
	 * @param   string   $typeAlias          The tag item name.
	 * @param   array    $explodedTypeAlias  Exploded alias if it exists
	 * @param   integer  $id                 Id of the item
	 *
	 * @return  string  The url string e.g. index.php?option=com_content&vew=article&id=3.
	 *
	 * @since   3.1
	 */
	public function getContentItemUrl($typeAlias, $id, $explodedTypeAlias = null)
	{
		if (!isset($explodedTypeAlias))
		{
			$explodedTypeAlias = self::explodedTypeAlias($typeAlias);
		}

		$this->url = 'index.php?option=' . $explodedTypeAlias[0] . '&view=' . $explodedTypeAlias[1] . '&id=' . $id;

		return $this->url;
	}

	/**
	 * Returns the url segment for a tag map record.
	 *
	 * @param   string   $typeAlias          Unknown
	 * @param   string   $explodedTypeAlias  The tag item name.
	 * @param   integer  $id                 The item ID
	 *
	 * @return  string  The url string e.g. index.php?option=com_content&vew=article&id=3.
	 *
	 * @since   3.1
	 */
	public function getTagUrl($typeAlias, $id, $explodedTypeAlias = null)
	{
		if (!isset($explodedTypeAlias))
		{
			$explodedTypeAlias = self::explodeTypeAlias($typeAlias);
		}

		$this->url = 'index.php&option=com_tags&view=tag&id=' . $id;

		return $this->url;
	}

	/**
	 * Method to get the table name for a type alias.
	 *
	 * @param   string  $tagItemAlias  A type alias.
	 *
	 * @return  string  Name of the table for a type
	 *
	 * @since   3.1
	 */
	public function getTableName($tagItemAlias)
	{
		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->select($db->quoteName('table'));
		$query->from($db->quoteName('#__content_types'));
		$query->where($db->quoteName('type_alias') . ' = ' . $db->quote($tagItemAlias));
		$db->setQuery($query);
		$this->table = $db->loadResult();

		return $this->table;
	}

	/**
	 * Method to get the type id for a type alias.
	 *
	 * @param   string  $typeAlias  A type alias.
	 *
	 * @return  string  Name of the table for a type
	 *
	 * @since   3.1
	 */
	public function getTypeId($typeAlias)
	{
		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->select($db->quoteName('type_id'));
		$query->from($db->quoteName('#__content_types'));
		$query->where($db->quoteName('type_alias') . ' = ' . $db->quote($typeAlias));
		$db->setQuery($query);
		$this->type_id = $db->loadResult();

		return $this->type_id;
	}

	/**
	 * Method to get a list of types with associated data.
	 *
	 * @param   string   $arrayType    Optionally specify that the returned list consist of objects, associative arrays, or arrays.
	 *                                 Options are: rowList, assocList, and objectList
	 * @param   array    $selectTypes  Optional array of type ids to limit the results to. Often from a request.
	 * @param   boolean  $useAlias     If true, the alias is used to match, if false the type_id is used.
	 *
	 * @return  array   Array of of types
	 *
	 * @since   3.1
	 */
	public static function getTypes($arrayType = 'objectList', $selectTypes = null, $useAlias = true)
	{
		// Initialize some variables.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*');

		if (!empty($selectTypes))
		{
			if (is_array($selectTypes))
			{
				$selectTypes = implode(',', $selectTypes);
			}
			if ($useAlias)
			{
				$query->where($db->qn('type_alias') . ' IN (' . $query->q($selectTypes) . ')');
			}
			else
			{
				$query->where($db->qn('type_id') . ' IN (' . $selectTypes . ')');
			}
		}

		$query->from($db->quoteName('#__content_types'));

		$db->setQuery($query);

		if (empty($arrayType) || $arrayType == 'objectList')
		{
			$types = $db->loadObjectList();
		}
		elseif ($arrayType == 'assocList')
		{
			$types = $db->loadAssocList();
		}
		else
		{
			$types = $db->loadRowList();
		}

		return $types;
	}

	/**
	 * Method to delete all instances of a tag from the mapping table. Generally used when a tag is deleted.
	 *
	 * @param   integer  $tag_id  The tag_id (primary key) for the deleted tag.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function tagDeleteInstances($tag_id)
	{
		// Delete the old tag maps.
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->delete();
		$query->from($db->quoteName('#__contentitem_tag_map'));
		$query->where($db->quoteName('tag_id') . ' = ' . (int) $tag_id);
		$db->setQuery($query);
		$db->execute();
	}
}
