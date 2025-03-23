# RCGR Data Files

This directory contains data files for the Resident Canada Goose Registration (RCGR) system. Below is a description of each file and its purpose.

## Core Data Tables

### Permit and Application Data
- **rcgr_permit_app_mast_202503031405.csv**: Master permit application data containing applicant information, contact details, permit status, issuance and expiration dates.
- **rcgr_permit_app_mast_hist_202503031405.csv**: Historical records of the permit application master table for audit and tracking purposes.

### Location Data
- **rcgr_location_202503031405.csv**: Contains location data for permitted activities including address details, county, city, state, and quantities of nests/eggs destroyed by month.
- **rcgr_location_hist_202503031405.csv**: Historical records of location data for tracking changes over time.

### Name Data
- **rcgr_name_202503031405.csv**: Associates person names with permit numbers and reporting years.
- **rcgr_name_hist_202503031405.csv**: Historical records of name associations for tracking changes.

### Report Data
- **rcgr_report_202503031405.csv**: Summarizes reporting data for permits, including location information and quantities of nests/eggs destroyed.
- **rcgr_report_hist_202503031405.csv**: Historical records of reports for audit and tracking purposes.

### User Profile Data
- **rcgr_userprofile_202503031405.csv**: Comprehensive user data including login credentials, contact information, business details, and certification status.

## System Tables

- **rcgr_sys_nn_202503031405.csv**: System tracking numbers table for numeric identifier management.
- **rcgr_sys_permit_number_gener_202503031405.csv**: Permit number generation system containing parameters for generating unique permit numbers.
- **rcgr_sys_california_access_key_202503031405.csv**: Special access keys for California locations.
- **rcgr_sys_california_access_key_log_202503031405.csv**: Log of California access key usage and changes.

## Reference Tables

- **rcgr_ref_applicant_request_type_202503031405.csv**: Reference table for different types of applicant requests.
- **rcgr_ref_application_status_202503031405.csv**: Reference table for possible application status values.
- **rcgr_ref_country_202503031405.csv**: Reference table for country codes and names.
- **rcgr_ref_flyways_202503031405.csv**: Reference table for bird migration flyway designations.
- **rcgr_ref_list_of_restricted_counties_202503031405.csv**: Reference table listing counties with special restrictions.
- **rcgr_ref_registrant_type_202503031405.csv**: Reference table for different types of registrants.
- **rcgr_ref_states_202503031405.csv**: Reference table for state codes and names.

## Data Structure Notes

- Files with the "_hist" suffix contain historical records and serve as an audit trail.
- All files include tracking fields like creation date, update date, and user identifiers.
- The timestamp in filenames (202503031405) represents the data extraction date/time.
