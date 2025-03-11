# FWS State Access

## Overview
This module provides state-based access control for users with the 'administer state based access' permission. It restricts their ability to view, create, and edit certain entities based on state code matching.

## Requirements
- Drupal 9 or 10
- User module (core)
- Taxonomy module (core)

## Features

### User Access Control
- Users with the 'administer state based access' permission can only access (view/edit/delete) other users that have the same `field_state_cd` value as their own.
- State administrators can create new users, but they will only be able to access those users if their state codes match.

### Entity Access Control
The module controls access to the following entity types:
- `species_image`
- `permit_3186a`

For these entities, state administrators can only:
- View entities where `field_owner_state` taxonomy term's state code matches their `field_state_cd`
- Create new entities (which will be restricted by their state code)
- Edit entities that belong to their state

## Configuration

1. Ensure the required fields exist:
   - User entity: `field_state_cd` (Entity reference to taxonomy terms)
   - Species Image entity: `field_owner_state` (Entity reference to taxonomy terms)
   - Permit 3186a entity: `field_owner_state` (Entity reference to taxonomy terms)

2. Grant the 'administer state based access' permission to appropriate roles:
   - Go to Administration » People » Permissions
   - Find the 'Administer state-based access' permission
   - Check the permission for roles that should have state-restricted access

3. Set the appropriate state code (`field_state_cd`) for each user who needs state-based access.

## Technical Details

### Permissions
The module defines the following permission:
- **Administer state-based access**: Allows users to manage content within their assigned state.

### Access Control Implementation
The module implements the following hooks:
- `hook_entity_access()`: Controls view/edit/delete operations
- `hook_entity_create_access()`: Controls entity creation
- `hook_node_access()`: Provides specific access control for nodes
- `hook_form_alter()`: Ensures state admins can access forms for their state's content
- `hook_preprocess_node()`: Adds an "Edit as State Admin" link for state-specific content

### Route-based Access Control
Additional route-based access checking is available through the `_state_access_check` requirement.

Example usage in routing.yml:
```yaml
example.route:
  path: '/example/path'
  defaults:
    _controller: '\Drupal\example\Controller\ExampleController::content'
  requirements:
    _state_access_check: 'TRUE'
```

### Caching
The module uses proper cache contexts and tags to ensure access checks are correctly cached:
- Cache contexts: `user`
- Cache tags: `user:[uid]` and `node:[nid]` where applicable

## Troubleshooting

### Common Issues
1. User cannot access any content:
   - Verify the user has the 'administer state based access' permission
   - Check if `field_state_cd` is properly set for the user

2. User can see all content:
   - Verify the module is enabled
   - Clear the Drupal cache
   - Check if the user has other permissions that might override state-based access

3. Entity access issues:
   - Confirm `field_owner_state` exists and contains valid values
   - Check that taxonomy term references are properly set

## Maintainers
- U.S. Fish and Wildlife Service
